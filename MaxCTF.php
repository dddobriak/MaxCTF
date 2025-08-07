<?php

declare(strict_types=1);

use Amp\File;
use function Amp\async;
use function Amp\delay;
use danog\AsyncOrm\DbArray;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\ValueType;
use danog\MadelineProto\LocalFile;
use danog\MadelineProto\SimpleEventHandler;
use danog\MadelineProto\EventHandler\Message;
use danog\AsyncOrm\Annotations\OrmMappedArray;
use danog\MadelineProto\EventHandler\CallbackQuery;
use danog\MadelineProto\EventHandler\Participant\Left;
use danog\MadelineProto\EventHandler\Attributes\Handler;
use EasyKeyboard\FluentKeyboard\ButtonTypes\InlineButton;
use danog\MadelineProto\EventHandler\Plugin\RestartPlugin;
use danog\MadelineProto\EventHandler\SimpleFilter\Incoming;
use EasyKeyboard\FluentKeyboard\ButtonTypes\KeyboardButton;
use danog\MadelineProto\EventHandler\Filter\FilterBotCommand;
use EasyKeyboard\FluentKeyboard\KeyboardTypes\KeyboardInline;
use EasyKeyboard\FluentKeyboard\KeyboardTypes\KeyboardMarkup;
use danog\MadelineProto\EventHandler\Channel\ChannelParticipant;
use danog\MadelineProto\EventHandler\ChatInviteRequester\BotChatInviteRequest;
use danog\MadelineProto\EventHandler\Filter\Combinator\FiltersOr;
use danog\MadelineProto\EventHandler\Filter\FilterTextStarts;

require_once 'vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

class BasicEventHandler extends SimpleEventHandler
{
    // !!! Change this to your username !!!
    public const ADMIN = "5856714760";
    public const STRATEGY = "https://t.me/MaxCTF";
    public const REVIEWS = "https://t.me/revMAXCTF";

    #[OrmMappedArray(KeyType::STRING, ValueType::BOOL)]
    private DbArray $queue;

    /**
     * Get peer(s) where to report errors.
     */
    public function getReportPeers()
    {
        return [self::ADMIN];
    }

    /**
     * Returns a set of plugins to activate.
     *
     * See here for more info on plugins: https://docs.madelineproto.xyz/docs/PLUGINS.html
     */
    public static function getPlugins(): array
    {
        return [
            // Offers a /restart command to admins that can be used to restart the bot, applying changes.
            // Make sure to run in a bash while loop when running via CLI to allow self-restarts.
            RestartPlugin::class,
        ];
    }

    /**
     * Handle incoming updates from users, chats and channels.
     */
    #[Handler]
    public function handleMessage(Incoming&Message $message): void
    {
        // Code that uses $message...
        // See the following pages for more examples and documentation:
        // - https://github.com/danog/MadelineProto/blob/v8/examples/bot.php
        // - https://docs.madelineproto.xyz/docs/UPDATES.html
        // - https://docs.madelineproto.xyz/docs/FILTERS.html
        // - https://docs.madelineproto.xyz/
    }

    #[Handler]
    public function handleNewUser(BotChatInviteRequest $request): void
    {
        // approves the chat join request
        $this->messages->hideChatJoinRequest(approved: true, peer: $request->chatId, user_id: $request->userId);
        $user = $this->getFullInfo($request->userId);

        // sends a welcome message to the user who joined the chat
        $this->messages->sendMedia(
            peer: $request->userId,
            media: [
                '_'   => 'inputMediaUploadedPhoto',
                'file' => new LocalFile(__DIR__ . '/img/start.jpg'),
            ],
            message: $this->peerName($user) . $this->db()['welcome'],
            parse_mode: 'markdown',
            reply_markup: KeyboardMarkup::new()
                ->singleUse()
                ->resize()
                ->row(KeyboardButton::Text('СТАРТ'))
                ->build()
        );

        if (isset($this->queue[$request->userId])) {
            // If the user is already in the queue, do not add them again
            return;
        }

        async(function () use ($request) {
            delay(30);

            $this->messages->sendMedia(
                peer: $request->userId,
                media: [
                    '_'   => 'inputMediaUploadedPhoto',
                    'file' => new LocalFile(__DIR__ . '/img/warmup.jpg'),
                ],
                message: $this->db()['warmup'],
                parse_mode: 'markdown',
                reply_markup: KeyboardInline::new()
                    ->row(
                        InlineButton::Url('💎 СТРАТЕГИЯ 💎', self::STRATEGY),
                        InlineButton::Url('✍🏻 ОТЗЫВЫ ✍🏻', self::REVIEWS)
                    )
                    ->build()
            );

            $this->queue[$request->userId] = true;
        });
    }

    #[Handler]
    public function onMemberLeft(ChannelParticipant $message): void
    {
        if ($message->newParticipant instanceof Left) {
            // If the user is an admin, do not remove them from the queue
            if ($message->userId == self::ADMIN) {
                return;
            }

            $this->messages->sendMessage(
                peer: $message->userId,
                message: $this->db()['faraway'],
                parse_mode: 'markdown'
            );

            // Remove the user from the queue
            unset($this->queue[$message->userId]);
        }
    }

    #[FiltersOr(
        new FilterBotCommand('start'),
        new FilterTextStarts('СТАРТ'),
    )]
    public function onPressStart(Message $message): void
    {
        $message->delete();
        $this->startMessage($message->senderId);
    }

    private function db(): array
    {
        $file = __DIR__ . '/db.json';

        if (!File\exists($file)) {
            return [];
        }

        try {
            $json = File\read($file);
        } catch (\Throwable $e) {
            return [];
        }

        return json_decode($json, true);
    }

    /**
     * Sends the initial message to the user when they press the "Start" button.
     *
     * @param int $peerId The ID of the peer (user) to send the message to.
     */
    private function startMessage($peerId): void
    {
        $this->messages->sendMedia(
            peer: $peerId,
            media: [
                '_'   => 'inputMediaUploadedPhoto',
                'file' => new LocalFile(__DIR__ . '/img/welcome.jpg'),
            ],
            message: $this->db()['about'],
            parse_mode: 'markdown',
            reply_markup: KeyboardInline::new()
                ->row(
                    InlineButton::Url('💎 СТРАТЕГИЯ 💎', self::STRATEGY),
                    InlineButton::Url('✍🏻 ОТЗЫВЫ ✍🏻', self::REVIEWS)
                )
                ->build()
        );
    }

    private function peerName(array $info): string
    {
        $username = $info['User']['username'] ?? '';
        $name = trim(($info['User']['first_name'] ?? '') . ' ' . ($info['User']['last_name'] ?? ''));

        return $name ? $name : "@$username";
    }
}

$settings = (new \danog\MadelineProto\Settings);

$app = (new \danog\MadelineProto\Settings\AppInfo)
    ->setApiId((int) $_ENV['API_ID'])
    ->setApiHash($_ENV['API_HASH']);

$db = (new \danog\MadelineProto\Settings\Database\Mysql)
    ->setDatabase($_ENV['DATABASE'])
    ->setUsername($_ENV['DB_USERNAME'])
    ->setPassword($_ENV['DB_PASSWORD']);

$settings->setAppInfo($app);
$settings->setDb($db);

BasicEventHandler::startAndLoopBot('MaxCTF.session', $_ENV['TOKEN'], $settings);
