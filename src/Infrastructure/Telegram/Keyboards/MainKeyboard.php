<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Telegram\Keyboards;

/**
 * Main menu keyboard builder.
 *
 * @package Zorvex\Infrastructure\Telegram\Keyboards
 */
final class MainKeyboard
{
    /**
     * Build XML-like JSON reply markup for inline buttons.
     *
     * @return array<string, mixed>
     */
    public function mainMenu(bool $isAdmin = false): array
    {
        $rows = [
            [
                ['text' => '🛒 خرید اشتراک', 'callback_data' => 'nav:services'],
                ['text' => '📦 اشتراک‌های من', 'callback_data' => 'nav:my'],
            ],
            [
                ['text' => '📞 پشتیبانی', 'callback_data' => 'nav:support'],
                ['text' => '💰 افزایش موجودی', 'callback_data' => 'nav:charge'],
            ],
            [
                ['text' => '📱 اپلیکیشن', 'web_app' => ['url' => $this->webAppUrl()]],
            ],
        ];

        if ($isAdmin) {
            $rows[] = [
                ['text' => '⚙️ پنل مدیریت', 'callback_data' => 'admin:panel'],
            ];
        }

        return ['inline_keyboard' => $rows];
    }

    /**
     * Persian formatted main menu caption.
     */
    public function menuText(string $name): string
    {
        return "سلام {$name} عزیز 👋\n"
            . "به ربات <b>Zorvex | Network</b> خوش آمدید.\n\n"
            . "⚡️ سرویس‌های ما با سرعت بالا و پایداری تضمینی در دسترس شما هستند.\n"
            . "از منوی زیر انتخاب کنید:";
    }

    private function webAppUrl(): string
    {
        return (string) config('app.url', 'https://zorvex.example.com') . '/miniapp';
    }
}