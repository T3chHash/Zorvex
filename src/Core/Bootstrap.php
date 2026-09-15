<?php

declare(strict_types=1);

namespace Zorvex\Core;

use Psr\Log\LoggerInterface;
use Zorvex\Application\UseCase\AuthenticateUser;
use Zorvex\Application\UseCase\ManageServer;
use Zorvex\Application\UseCase\ProcessPayment;
use Zorvex\Application\UseCase\PurchaseSubscription;
use Zorvex\Application\UseCase\RenewSubscription;
use Zorvex\Domain\Entity\User;
use Zorvex\Domain\Repository\PaymentRepository;
use Zorvex\Domain\Repository\ServerRepository;
use Zorvex\Domain\Repository\SubscriptionRepository;
use Zorvex\Domain\Repository\UserRepository;
use Zorvex\Domain\Service\PanelManager;
use Zorvex\Domain\Service\PaymentGateway;
use Zorvex\Infrastructure\Database\MySQLPaymentRepository;
use Zorvex\Infrastructure\Database\MySQLServerRepository;
use Zorvex\Infrastructure\Database\MySQLSubscriptionRepository;
use Zorvex\Infrastructure\Database\MySQLUserRepository;
use Zorvex\Infrastructure\Panel\MarzbanPanel;
use Zorvex\Infrastructure\Panel\XUIPanel;
use Zorvex\Infrastructure\Payment\CardToCardGateway;
use Zorvex\Infrastructure\Payment\CryptoGateway;
use Zorvex\Infrastructure\Payment\ZarinpalGateway;
use Zorvex\Infrastructure\Telegram\Authenticator;
use Zorvex\Infrastructure\Telegram\Commands\AdminCommand;
use Zorvex\Infrastructure\Telegram\Commands\BuyCommand;
use Zorvex\Infrastructure\Telegram\Commands\MenuCommand;
use Zorvex\Infrastructure\Telegram\Commands\ServicesCommand;
use Zorvex\Infrastructure\Telegram\Commands\StartCommand;
use Zorvex\Infrastructure\Telegram\Commands\SupportCommand;
use Zorvex\Infrastructure\Telegram\Keyboards\MainKeyboard;
use Zorvex\Infrastructure\Telegram\Keyboards\PaymentKeyboard;
use Zorvex\Infrastructure\Telegram\Keyboards\ServiceKeyboard;
use Zorvex\Infrastructure\Telegram\TelegramBot;
use Zorvex\Infrastructure\Telegram\WebhookHandler;
use Zorvex\Infrastructure\Telegram\WebhookRateLimiter;

/**
 * Application bootstrap: load configuration, helpers, and register every
 * service into the shared container.
 *
 * @package Zorvex\Core
 */
final class Bootstrap
{
    private Container $container;

    private bool $booted = false;

    private function __construct()
    {
        $this->container = Container::instance();
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * Initialise the application once for the current request.
     */
    public function boot(): Container
    {
        if ($this->booted) {
            return $this->container;
        }

        // Error reporting tuned by environment.
        $debug = (bool) config('app.debug', false);
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        date_default_timezone_set((string) config('app.timezone', 'Asia/Tehran'));

        $this->registerCore();
        $this->registerRepositories();
        $this->registerPanels();
        $this->registerGateways();
        $this->registerTelegram();

        $this->booted = true;
        logger()->debug('Application booted');

        return $this->container;
    }

    /**
     * Register the application and error logger.
     */
    private function registerCore(): void
    {
        $container = $this->container;

        // Database (primary shared connection).
        $container->singleton(Database::class, static fn (): Database => Database::fromConfig());

        // Event bus for lifecycle events.
        $container->singleton(EventBus::class, static fn (): EventBus => new EventBus());

        // Router with a route-params bag.
        $container->singleton(RouteParams::class, static fn (): RouteParams => new RouteParams());

        // Logger (PSR-3).
        $container->singleton(LoggerInterface::class, static function () use ($container): Logger {
            $logger = new Logger((string) config('log.path', ''), (string) config('log.level', 'debug'));
            $logger->attachBus($container->get(EventBus::class));

            return $logger;
        });

        // Request + Response catch-alls so autowiring always works.
        $container->bind(Request::class, static fn (): Request => Request::capture());
        $container->bind(Response::class, static fn (): Response => new Response());
    }

    private function registerRepositories(): void
    {
        $container = $this->container;

        $container->alias(UserRepository::class, MySQLUserRepository::class);
        $container->alias(ServerRepository::class, MySQLServerRepository::class);
        $container->alias(SubscriptionRepository::class, MySQLSubscriptionRepository::class);
        $container->alias(PaymentRepository::class, MySQLPaymentRepository::class);

        // Concrete repo classes are autowirable through the container because
        // they only depend on the bound Database service — no alias needed.
    }

    private function registerPanels(): void
    {
        $container = $this->container;

        // Concrete panel clients.
        $container->singleton(MarzbanPanel::class, static fn ($c): MarzbanPanel => new MarzbanPanel($c->get(LoggerInterface::class)));
        $container->singleton(XUIPanel::class, static fn ($c): XUIPanel => new XUIPanel($c->get(LoggerInterface::class)));

        // Iterable collection of supported panels.
        $container->singleton(
            'panels',
            static fn ($c): iterable => [
                $c->get(MarzbanPanel::class),
                $c->get(XUIPanel::class),
            ]
        );

        // NOTE: PanelManager and PaymentGateway are not globally aliased to the
        // iterable because autowiring against the interface would try to inject
        // a single instance. Use cases receive the iterable via `'panels'` /
        // `'gateways'` keys instead.
    }

    private function registerGateways(): void
    {
        $container = $this->container;

        $container->singleton(CardToCardGateway::class, static fn ($c): CardToCardGateway => new CardToCardGateway($c->get(Database::class)));
        $container->singleton(ZarinpalGateway::class, static fn ($c): ZarinpalGateway => new ZarinpalGateway($c->get(LoggerInterface::class)));
        $container->singleton(CryptoGateway::class, static fn (): CryptoGateway => new CryptoGateway());

        $container->singleton(
            'gateways',
            static fn ($c): iterable => [
                $c->get(CryptoGateway::class),
                $c->get(CardToCardGateway::class),
                $c->get(ZarinpalGateway::class),
            ]
        );
    }

    private function registerTelegram(): void
    {
        $container = $this->container;

        $container->singleton(TelegramBot::class, static fn ($c): TelegramBot => new TelegramBot(
            (string) config('telegram.token', ''),
            $c->get(LoggerInterface::class)
        ));

        $container->singleton(WebhookRateLimiter::class, static fn (): WebhookRateLimiter => new WebhookRateLimiter());

        $container->singleton(Authenticator::class, static fn ($c): Authenticator => new Authenticator(
            new AuthenticateUser($c->get(UserRepository::class))
        ));

        // Keyboards are stateless builders.
        $container->bind(MainKeyboard::class, static fn (): MainKeyboard => new MainKeyboard());
        $container->bind(ServiceKeyboard::class, static fn (): ServiceKeyboard => new ServiceKeyboard());
        $container->bind(PaymentKeyboard::class, static fn (): PaymentKeyboard => new PaymentKeyboard());

        // Use cases.
        $container->singleton(AuthenticateUser::class, static fn ($c): AuthenticateUser => new AuthenticateUser($c->get(UserRepository::class)));
        $container->singleton(PurchaseSubscription::class, static fn ($c): PurchaseSubscription => new PurchaseSubscription(
            $c->get(UserRepository::class),
            $c->get(ServerRepository::class),
            $c->get(SubscriptionRepository::class),
            $c->get('panels'),
            $c->get(LoggerInterface::class)
        ));
        $container->singleton(RenewSubscription::class, static fn ($c): RenewSubscription => new RenewSubscription(
            $c->get(UserRepository::class),
            $c->get(SubscriptionRepository::class),
            $c->get(ServerRepository::class),
            $c->get('panels'),
            $c->get(LoggerInterface::class)
        ));
        $container->singleton(ProcessPayment::class, static fn ($c): ProcessPayment => new ProcessPayment(
            $c->get(UserRepository::class),
            $c->get(PaymentRepository::class),
            $c->get(SubscriptionRepository::class),
            $c->get(PurchaseSubscription::class),
            $c->get(RenewSubscription::class),
            $c->get(Database::class),
            $c->get('gateways'),
            $c->get(LoggerInterface::class)
        ));
        $container->singleton(ManageServer::class, static fn ($c): ManageServer => new ManageServer(
            $c->get(ServerRepository::class),
            $c->get(SubscriptionRepository::class),
            $c->get('panels'),
            $c->get(LoggerInterface::class)
        ));

        // Webhook handler wiring (commands registered at boot).
        $container->singleton(WebhookHandler::class, static function ($c): WebhookHandler {
            $handler = new WebhookHandler(
                $c->get(TelegramBot::class),
                $c->get(WebhookRateLimiter::class),
                $c->get(EventBus::class),
                $c->get(LoggerInterface::class),
                $c->get(Authenticator::class)
            );

            $handler->register($c->get(StartCommand::class));
            $handler->register($c->get(MenuCommand::class));
            $handler->register($c->get(ServicesCommand::class));
            $handler->register($c->get(BuyCommand::class));
            $handler->register($c->get(SupportCommand::class));
            $handler->register($c->get(AdminCommand::class));

            return $handler;
        });

        // Commands.
        $container->bind(StartCommand::class, static fn ($c): StartCommand => new StartCommand(
            $c->get(TelegramBot::class),
            $c->get(AuthenticateUser::class),
            $c->get(MainKeyboard::class),
            $c->get(EventBus::class)
        ));
        $container->bind(MenuCommand::class, static fn ($c): MenuCommand => new MenuCommand(
            $c->get(TelegramBot::class),
            $c->get(AuthenticateUser::class),
            $c->get(MainKeyboard::class),
            $c->get(SubscriptionRepository::class)
        ));
        $container->bind(ServicesCommand::class, static fn ($c): ServicesCommand => new ServicesCommand(
            $c->get(TelegramBot::class),
            $c->get(SubscriptionRepository::class),
            $c->get(ServiceKeyboard::class)
        ));
        $container->bind(BuyCommand::class, static fn ($c): BuyCommand => new BuyCommand(
            $c->get(TelegramBot::class),
            $c->get(PurchaseSubscription::class),
            $c->get(ProcessPayment::class),
            $c->get(ServiceKeyboard::class),
            $c->get(PaymentKeyboard::class)
        ));
        $container->bind(SupportCommand::class, static fn ($c): SupportCommand => new SupportCommand(
            $c->get(TelegramBot::class),
            $c->get(ServiceKeyboard::class)
        ));
        $container->bind(AdminCommand::class, static fn ($c): AdminCommand => new AdminCommand(
            $c->get(TelegramBot::class)
        ));
    }
}