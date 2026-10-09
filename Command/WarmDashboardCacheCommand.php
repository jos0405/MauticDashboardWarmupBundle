<?php

declare(strict_types=1);

namespace MauticPlugin\MauticDashboardWarmupBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\DashboardBundle\Entity\Widget;
use Mautic\DashboardBundle\Model\DashboardModel;
use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Security\UserTokenSetter;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Pre-computes dashboard widget data so an interactive login does not have to.
 *
 * DashboardController::indexAction() renders every widget synchronously and caches each
 * result for `cached_data_timeout` minutes. Whoever loads the dashboard on a cold cache
 * pays for every widget query. Running this from cron moves that cost off the request.
 *
 * Widgets are owned per user, and both permissions and the user timezone feed the cached
 * value, so each user is warmed under their own security token.
 */
#[AsCommand(
    name: WarmDashboardCacheCommand::COMMAND_NAME,
    description: 'Pre-computes dashboard widget data so the first login does not have to wait for it'
)]
class WarmDashboardCacheCommand extends Command
{
    public const COMMAND_NAME = 'mautic:dashboard:warm-cache';

    public function __construct(
        private DashboardModel $dashboardModel,
        private UserTokenSetter $userTokenSetter,
        private TokenStorageInterface $tokenStorage,
        private RequestStack $requestStack,
        private EntityManagerInterface $em,
        private LocaleAwareInterface $translator,
        private CoreParametersHelper $coreParametersHelper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Warm only this user, by ID or username. Repeatable. Defaults to every published user.'
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Drop cached widget data before warming. This clears cached widgets for every user, not only the ones being warmed.'
            )
            ->setHelp(<<<'EOT'
The <info>%command.name%</info> command computes each user's dashboard widgets and stores
the result in the widget cache, so the dashboard renders from cache on the next login.

    <info>bin/console %command.name%</info>
    <info>bin/console %command.name% --user=admin --user=3</info>

Run it as the same system user that serves the site (usually www-data), otherwise the
cache files it writes will not be readable by the web request and warming has no effect.

Warming uses the default dashboard date range. A user who picked a custom range in the UI
has that range stored in their PHP session, which produces a different cache key, so their
dashboard is not covered by this command.
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $users = $this->resolveUsers($input->getOption('user'));

        if ([] === $users) {
            $io->error('No matching published user found.');

            return Command::FAILURE;
        }

        // getDefaultFilter() reads the date range from the session, which does not exist on
        // the CLI. Give it an empty one so it falls back to the default range.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack->push($request);

        $originalToken    = $this->tokenStorage->getToken();
        $originalLocale   = $this->translator->getLocale();
        $originalTimezone = date_default_timezone_get();
        $cleared          = false;
        $failed           = 0;
        $totalSeconds     = 0.0;

        try {
            foreach ($users as $user) {
                $this->userTokenSetter->setUser($user['id']);

                // The web request resolves both of these per user, and the locale is part of
                // the widget cache key. Warming under the CLI defaults would store entries
                // under a key the user's own request never looks up.
                $this->translator->setLocale($user['locale']);
                date_default_timezone_set($user['timezone']);

                if ($input->getOption('force') && !$cleared) {
                    $this->dashboardModel->clearDashboardCache();
                    $cleared = true;
                }

                $io->section(sprintf('%s (#%d, %s, %s)', $user['username'], $user['id'], $user['locale'], $user['timezone']));

                $filter  = $this->dashboardModel->getDefaultFilter();
                $widgets = $this->dashboardModel->getWidgets();

                if (!count($widgets)) {
                    $io->text('No widgets.');

                    continue;
                }

                $rows = [];

                foreach ($widgets as $widget) {
                    \assert($widget instanceof Widget);

                    $started = microtime(true);
                    $this->dashboardModel->populateWidgetContent($widget, $filter);
                    $elapsed = microtime(true) - $started;

                    $totalSeconds += $elapsed;

                    // populateWidgetContent() swallows widget exceptions and records them on
                    // the entity, so this is the only place a failure becomes visible.
                    $error = $widget->getErrorMessage();

                    if ($error) {
                        ++$failed;
                    }

                    $rows[] = [
                        $widget->getType(),
                        sprintf('%.2fs', $elapsed),
                        $widget->isCached() ? 'cached' : 'computed',
                        $error ?: '',
                    ];
                }

                $io->table(['Widget', 'Time', 'Source', 'Error'], $rows);

                // Widgets get hydrated with template data while warming. Drop them so the
                // next user does not inherit a growing identity map.
                $this->em->clear();
            }
        } finally {
            $this->tokenStorage->setToken($originalToken);
            $this->translator->setLocale($originalLocale);
            date_default_timezone_set($originalTimezone);
            $this->requestStack->pop();
        }

        $io->success(sprintf('Warmed %d user(s) in %.2fs.', count($users), $totalSeconds));

        if ($failed) {
            $io->warning(sprintf('%d widget(s) failed to load. See the Mautic log for details.', $failed));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Resolved up front and kept as scalars, because the loop clears the entity manager
     * between users and would otherwise be left holding detached User entities.
     *
     * Locale and timezone fall back the same way the web request does, so warming produces
     * the cache key and the day bucketing that user's own dashboard will ask for.
     *
     * @param string[] $identifiers
     *
     * @return array<int, array{id: int, username: string, locale: string, timezone: string}>
     */
    private function resolveUsers(array $identifiers): array
    {
        $repository = $this->em->getRepository(User::class);

        if ([] === $identifiers) {
            $users = $repository->findBy(['isPublished' => true]);
        } else {
            $users = [];

            foreach ($identifiers as $identifier) {
                $user = ctype_digit($identifier)
                    ? $repository->find((int) $identifier)
                    : $repository->findOneBy(['username' => $identifier]);

                if ($user instanceof User) {
                    $users[] = $user;
                }
            }
        }

        $defaultLocale   = (string) $this->coreParametersHelper->get('locale');
        $defaultTimezone = (string) $this->coreParametersHelper->getDefaultTimezone();
        $resolved        = [];

        foreach ($users as $user) {
            $resolved[(int) $user->getId()] = [
                'id'       => (int) $user->getId(),
                'username' => (string) $user->getUsername(),
                'locale'   => $user->getLocale() ?: $defaultLocale,
                'timezone' => $user->getTimezone() ?: $defaultTimezone,
            ];
        }

        return array_values($resolved);
    }
}
