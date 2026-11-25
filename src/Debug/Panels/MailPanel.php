<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Debug\Panels;

use Dacheng\Yii2\Swoole\Debug\Module;
use Swoole\Coroutine;
use Yii;
use yii\base\Event;
use yii\debug\Panel;
use yii\debug\panels\MailPanel as BaseMailPanel;
use yii\helpers\FileHelper;
use yii\mail\BaseMailer;
use yii\mail\MessageInterface;

/**
 * Coroutine-safe MailPanel storing captured email metadata per coroutine instead of on a
 * shared worker-wide array.
 */
class MailPanel extends BaseMailPanel
{
    private const CONTEXT_KEY = '__yii2SwooleDebugMails';

    /** @var array<int, array<string, mixed>> */
    private array $fallbackMessages = [];

    private bool $listenerAttached = false;

    public function init(): void
    {
        // Intentionally skip BaseMailPanel::init() to avoid registering its shared $_messages listener.
        Panel::init();

        if ($this->listenerAttached) {
            return;
        }
        $this->listenerAttached = true;

        $module = $this->module;

        Event::on('yii\mail\BaseMailer', BaseMailer::EVENT_AFTER_SEND, function ($event) use ($module): void {
            if ($module instanceof Module && $module->isCurrentCoroutineMuted()) {
                return;
            }

            /** @var MessageInterface $message */
            $message = $event->message;
            $messageData = [
                'isSuccessful' => $event->isSuccessful,
                'from' => $this->formatAddress($message->getFrom()),
                'to' => $this->formatAddress($message->getTo()),
                'reply' => $this->formatAddress($message->getReplyTo()),
                'cc' => $this->formatAddress($message->getCc()),
                'bcc' => $this->formatAddress($message->getBcc()),
                'subject' => $message->getSubject(),
                'charset' => $message->getCharset(),
                'body' => $message->toString(),
                'headers' => '',
                'time' => time(),
            ];

            $fileName = $event->sender->generateMessageFileName();
            $mailPath = Yii::getAlias($this->mailPath);
            FileHelper::createDirectory($mailPath);
            file_put_contents($mailPath . '/' . $fileName, $message->toString());
            $messageData['file'] = $fileName;

            if (extension_loaded('swoole') && Coroutine::getCid() >= 0) {
                $context = Coroutine::getContext();
                $messages = $context[self::CONTEXT_KEY] ?? [];
                $messages[] = $messageData;
                $context[self::CONTEXT_KEY] = $messages;
                return;
            }

            $this->fallbackMessages[] = $messageData;
        });
    }

    public function save(): array
    {
        if (extension_loaded('swoole') && Coroutine::getCid() >= 0) {
            $context = Coroutine::getContext();
            $messages = $context[self::CONTEXT_KEY] ?? [];
            unset($context[self::CONTEXT_KEY]);

            return is_array($messages) ? $messages : [];
        }

        $messages = $this->fallbackMessages;
        $this->fallbackMessages = [];

        return $messages;
    }

    public function getMessagesFileName(): array
    {
        $messages = (extension_loaded('swoole') && Coroutine::getCid() >= 0)
            ? (Coroutine::getContext()[self::CONTEXT_KEY] ?? [])
            : $this->fallbackMessages;

        $names = [];
        if (is_array($messages)) {
            foreach ($messages as $message) {
                if (isset($message['file'])) {
                    $names[] = $message['file'];
                }
            }
        }

        return $names;
    }

    private function formatAddress(mixed $attr): mixed
    {
        if (is_array($attr)) {
            return implode(', ', array_keys($attr));
        }

        return $attr;
    }
}
