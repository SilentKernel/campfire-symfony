<?php

declare(strict_types=1);

namespace App\Tests\Unit\Routing;

use App\Controller\ApplicationController;
use App\Http\Attribute\ActionNotFound;
use App\Http\Attribute\AllowBotAccess;
use App\Http\Attribute\AllowUnauthenticatedAccess;
use App\Http\Attribute\NotApplicationController;
use App\Http\Attribute\RequireUnauthenticatedAccess;
use App\Http\Attribute\SkipForgeryProtection;
use App\Tests\Functional\Routing\AppRoutes;
use App\Tests\Functional\Routing\RailsRouteRecognizer;
use PHPUnit\Framework\TestCase;

/**
 * The access attributes mirror the Rails class macros (reference/app/controllers).
 */
final class ControllerAttributesTest extends TestCase
{
    public function testAccessAttributesMatchRails(): void
    {
        self::assertSame(
            [
                'accounts/logos#show',
                'first_runs#create',
                'first_runs#show',
                'pwa#manifest',
                'pwa#service_worker',
                'qr_code#show',
                'sessions#create',
                'sessions#new',
                'sessions/transfers#show',
                'sessions/transfers#update',
            ],
            $this->actionsWith(AllowUnauthenticatedAccess::class),
        );
        self::assertSame(['users#create', 'users#new'], $this->actionsWith(RequireUnauthenticatedAccess::class));
        self::assertSame(
            [
                'messages/boosts/by_bots#create',
                'messages/boosts/by_bots#destroy',
                'messages/by_bots#create',
                'messages/by_bots#destroy',
                'messages/by_bots#index',
                'messages/by_bots#update',
            ],
            $this->actionsWith(AllowBotAccess::class),
        );
    }

    public function testForgeryProtectionIsSkippedWhereRailsSkipsIt(): void
    {
        $skipped = array_values(array_unique(array_map(
            static fn (string $action): string => strstr($action, '#', true),
            $this->actionsWith(SkipForgeryProtection::class),
        )));

        self::assertSame(
            [
                'action_mailbox/ingresses/mailgun/inbound_emails',
                'action_mailbox/ingresses/mandrill/inbound_emails',
                'action_mailbox/ingresses/postmark/inbound_emails',
                'action_mailbox/ingresses/relay/inbound_emails',
                'action_mailbox/ingresses/sendgrid/inbound_emails',
                'active_storage/disk',
                'pwa',
            ],
            $skipped,
        );
    }

    public function testOnlyFrameworkControllersAreNotApplicationControllers(): void
    {
        foreach ($this->controllerClasses() as $class) {
            $reflection = new \ReflectionClass($class);
            if ([] !== $reflection->getAttributes(ActionNotFound::class)) {
                continue;
            }
            $framework = 1 === preg_match('/^App\\\\Controller\\\\(Rails|ActiveStorage|Turbo|ActionMailbox)\\\\/', $class);

            self::assertSame($framework, [] !== $reflection->getAttributes(NotApplicationController::class), $class);
            self::assertSame(!$framework, $reflection->isSubclassOf(ApplicationController::class), $class);
        }
    }

    public function testMissingRailsActionsAre404s(): void
    {
        $missing = $this->actionsWith(ActionNotFound::class, includeClassLevel: false);

        self::assertContains('first_runs#edit', $missing);
        self::assertContains('rooms/directs#update', $missing);
        self::assertContains('messages#new', $missing);
        self::assertNotContains('rooms/opens#index', $missing); // inherited from RoomsController
        self::assertNotContains('messages/by_bots#update', $missing); // inherited from MessagesController
        self::assertCount(33, $missing);
    }

    /**
     * @param class-string $attribute
     *
     * @return list<string> Rails endpoints whose action or class carries the attribute
     */
    private function actionsWith(string $attribute, bool $includeClassLevel = true): array
    {
        $actions = [];
        foreach (AppRoutes::load()->all() as $route) {
            $controller = (string) $route->getDefault('_controller');
            [$class, $method] = explode('::', $controller);
            $method = new \ReflectionMethod($class, $method);
            if (ActionNotFound::class !== $attribute && [] !== $method->getAttributes(ActionNotFound::class)) {
                continue; // Rails raises before any filter runs
            }
            $classLevel = $includeClassLevel && [] !== $method->getDeclaringClass()->getAttributes($attribute);
            if ($classLevel || [] !== $method->getAttributes($attribute)) {
                $actions[] = (string) RailsRouteRecognizer::endpoint($controller);
            }
        }
        $actions = array_values(array_unique($actions));
        sort($actions);

        return $actions;
    }

    /**
     * @return list<class-string>
     */
    private function controllerClasses(): array
    {
        $classes = [];
        foreach (AppRoutes::load()->all() as $route) {
            $classes[] = explode('::', (string) $route->getDefault('_controller'))[0];
        }

        return array_values(array_unique($classes));
    }
}
