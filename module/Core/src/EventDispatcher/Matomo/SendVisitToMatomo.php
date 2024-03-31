<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Core\EventDispatcher\Matomo;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Shlinkio\Shlink\Core\EventDispatcher\Event\VisitLocated;
use Shlinkio\Shlink\Core\Matomo\MatomoOptions;
use Shlinkio\Shlink\Core\Matomo\MatomoTrackerBuilderInterface;
use Shlinkio\Shlink\Core\ShortUrl\Helper\ShortUrlStringifier;
use Shlinkio\Shlink\Core\Visit\Entity\Visit;
use Throwable;

readonly class SendVisitToMatomo
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private ShortUrlStringifier $shortUrlStringifier,
        private MatomoOptions $matomoOptions,
        private MatomoTrackerBuilderInterface $trackerBuilder,
    ) {
    }

    public function __invoke(VisitLocated $visitLocated): void
    {
        if (! $this->matomoOptions->enabled) {
            return;
        }

        $visitId = $visitLocated->visitId;

        /** @var Visit|null $visit */
        $visit = $this->em->find(Visit::class, $visitId);
        if ($visit === null) {
            $this->logger->warning('Tried to send visit with id "{visitId}" to matomo, but it does not exist.', [
                'visitId' => $visitId,
            ]);
            return;
        }

        try {
            $tracker = $this->trackerBuilder->buildMatomoTracker();

            $tracker
                ->setUrl($this->resolveUrlToTrack($visit))
                ->setCustomTrackingParameter('type', $visit->type->value)
                ->setUserAgent($visit->userAgent)
                ->setUrlReferrer($visit->referer);

            $location = $visit->getVisitLocation();
            if ($location !== null) {
                $tracker
                    ->setCity($location->cityName)
                    ->setCountry($location->countryName)
                    ->setLatitude($location->latitude)
                    ->setLongitude($location->longitude);
            }

            // Set not obfuscated IP if possible, as matomo handles obfuscation itself
            $ip = $visitLocated->originalIpAddress ?? $visit->remoteAddr;
            if ($ip !== null) {
                $tracker->setIp($ip);
            }

            if ($visit->isOrphan()) {
                $tracker->setCustomTrackingParameter('orphan', 'true');
            }

            // Send the short URL title or an empty document title to avoid different actions to be created by matomo
            $tracker->doTrackPageView($visit->shortUrl?->title() ?? '');
        } catch (Throwable $e) {
            // Capture all exceptions to make sure this does not interfere with the regular execution
            $this->logger->error('An error occurred while trying to send visit to Matomo. {e}', ['e' => $e]);
        }
    }

    public function resolveUrlToTrack(Visit $visit): string
    {
        $shortUrl = $visit->shortUrl;
        if ($shortUrl === null) {
            return $visit->visitedUrl ?? '';
        }

        return $this->shortUrlStringifier->stringify($shortUrl);
    }
}
