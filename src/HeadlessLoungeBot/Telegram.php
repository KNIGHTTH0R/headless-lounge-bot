<?php
declare(strict_types=1);
namespace Soatok\HeadlessLoungeBot;

use GuzzleHttp\Client;
use Interop\Container\Exception\ContainerException;
use ParagonIE\Certainty\Exception\CertaintyException;
use ParagonIE\Certainty\RemoteFetch;
use ParagonIE\EasyDB\EasyDB;
use ParagonIE\HiddenString\HiddenString;
use Psr\Http\Message\ResponseInterface;
use Slim\Container;
use Soatok\DholeCrypto\Exceptions\CryptoException;
use Soatok\DholeCrypto\Key\SymmetricKey;
use Soatok\HeadlessLoungeBot\Splices\Channels;
use Soatok\HeadlessLoungeBot\Splices\Users;
use Soatok\HeadlessLoungeBot\TelegramTraits\NewMessageTrait;

/**
 * Class Telegram
 * @package Soatok\HeadlessLoungeBot
 */
class Telegram
{
    use NewMessageTrait;

    /** @var string $baseUrl */
    protected $baseUrl;

    /** @var string $botUsername */
    protected $botUsername;

    /** @var int $botUserId */
    protected $botUserId;

    /** @var Channels $channels */
    protected $channels;

    /** @var Container $container */
    protected $container;

    /** @var bool $debug */
    protected $debug = false;

    /** @var EasyDB $db */
    protected $db;

    /** @var SymmetricKey $encKey */
    protected $encKey;

    /** @var Client $http */
    protected $http;

    /** @var Patreon $patreon */
    protected $patreon;

    /** @var HiddenString $token */
    protected $token;

    /** @var Twitch $twitch */
    protected $twitch;

    /** @var Users $users */
    protected $users;

    /**
     * Telegram constructor.
     * @param Container $c
     * @param Twitch|null $twitch
     * @param Client|null $http
     * @throws CertaintyException
     * @throws ContainerException
     * @throws \SodiumException
     */
    public function __construct(Container $c, ?Twitch $twitch = null, ?Client $http = null)
    {
        /** @var HiddenString|string $token */
        $token = $c['settings']['telegram'];
        if (is_string($token)) {
            $token = new HiddenString($token);
        }
        if (!($token instanceof HiddenString)) {
            throw new \TypeError('Token must be an instance of HiddenString');
        }
        /** @var string $botName */
        $botName = $c['settings']['tg-bot-username'];
        $this->baseUrl = $c['settings']['base-url'];
        $this->botUsername = $botName;
        $this->botUserId = $c['settings']['tg-bot-user-id'];
        $this->encKey = $c['settings']['encryption-key'];
        $this->db = $c->get('db');
        $this->debug = $c['settings']['bot-debug'];
        $this->token = $token;
        if (!$http) {
            $http = new Client([
                'verify' =>
                    (new RemoteFetch(APP_ROOT . '/local/'))
                        ->getLatestBundle()
            ]);
        }
        $this->http = $http;
        $this->patreon = new Patreon($c);
        if (!$twitch) {
            $twitch = new Twitch($c, $this->http);
        }
        $this->twitch = $twitch;
        $this->container = $c;

        // Splices:
        $this->channels = new Channels($c);
        $this->users = new Users($c);
    }

    /**
     * @param string $url
     * @return array
     */
    public function setupWebook(string $url)
    {
        return $this->apiRequest('setWebhook', ['url' => $url]);
    }

    /**
     * @return HiddenString
     */
    public function getToken(): HiddenString
    {
        return $this->token;
    }

    /**
     * Process an update
     *
     * @param array $update
     * @return self
     */
    public function processUpdate(array $update): self
    {
        if ($update['from']['id'] === $this->botUserId) {
            return $this;
        }
        try {
            file_put_contents(APP_ROOT . '/local/last_update_id.txt', $update['update_id']);
            if (isset($update['message'])) {
                $this->processNewMessage($update['message']);
            }
            // else {
            // Catch all for unknown update types
            if (!is_dir(APP_ROOT . '/local/updates')) {
                mkdir(APP_ROOT . '/local/updates', 0777);
            }
            if ($this->debug) {
                file_put_contents(
                    APP_ROOT . '/local/updates/' . time() . '-' . $update['update_id'] . '.json',
                    json_encode($update, JSON_PRETTY_PRINT)
                );
            }
            // }
        } catch (\Throwable $ex) {
            file_put_contents(
                APP_ROOT . '/local/updates/' . time() . '-' . $update['update_id'] . '-error.json',
                json_encode([
                    'message' => $ex->getMessage(),
                    'trace' => $ex->getTrace()
                ], JSON_PRETTY_PRINT)
            );
        }
        return $this;
    }

    /**
     * @param array $update
     * @return bool
     * @throws CryptoException
     * @throws \Patreon\Exceptions\APIException
     * @throws \Patreon\Exceptions\CurlException
     * @throws \SodiumException
     */
    protected function processNewMessage(array $update)
    {
        $type = $update['chat']['type'];
        if ($update['from']['id'] === $this->botUserId) {
            return false;
        }
        switch ($type) {
            case 'private':
                return $this->newMessagePrivate($update);
            case 'group':
            case 'supergroup':
                return $this->newMessageGroup($update);
            default:
                $this->sendMessage('DEBUG: Unknown update type, sorry...', [
                    'chat_id' => $update['chat']['id']
                ]);
                return false;
        }
    }

    /**
     * @param int $channel
     * @return array
     */
    protected function getAdministrators(int $channel): array
    {
        $result = $this->apiRequest('getChatAdministrators', [
            'chat_id' => $channel
        ]);
        return $result['result'];
    }

    /**
     * Implements the getUpdates strategy for getting updates from Telegram.
     *
     * @return array
     */
    public function getUpdates(): array
    {
        if (is_readable(APP_ROOT . '/local/last_update_id.txt')) {
            $update_id = (int)file_get_contents(APP_ROOT . '/local/last_update_id.txt');
            $response = $this->apiRequest('getUpdates', ['offset' => $update_id]);
        } else {
            $update_id = 0;
            $response = $this->apiRequest('getUpdates');
        }
        $max_update_id = $update_id;
        foreach ($response['result'] as $row) {
            $max_update_id = max($row['update_id'], $max_update_id);
            $this->processUpdate($row);
        }
        file_put_contents(APP_ROOT . '/local/last_update_id.txt', $max_update_id);
        return $response['result'];
    }

    /**
     * @param string $method
     * @param array $params
     * @return array
     */
    public function apiRequest(string $method, array $params = []): array
    {
        return $this->parseJson(
            $this->http->post(
                $this->getRequestUri($method),
                ['json' => $params]
            )
        );
    }

    /**
     * @param string $message
     * @param array $params
     * @return array
     */
    public function sendMessage(string $message, array $params = []): array
    {
        if (empty($params['parse_mode'])) {
            $params['parse_mode'] = 'Markdown';
        }
        return $this->apiRequest(
            'sendMessage',
            ['text' => $message] + $params
        );
    }

    /**
     * @param string $method
     * @return string
     */
    public function getRequestUri(string $method): string
    {
        return 'https://api.telegram.org/bot' . $this->token->getString() . '/' . $method;
    }

    /**
     * @param ResponseInterface $response
     * @return array
     */
    public function parseJson(ResponseInterface $response): array
    {
        $body = (string) $response->getBody()->getContents();
        return json_decode($body, true);
    }
}
