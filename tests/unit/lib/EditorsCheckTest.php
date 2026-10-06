<?php

declare(strict_types=1);

/**
 *
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH or a Nextcloud affiliate company and Euro-Office contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 */

namespace OCA\Eurooffice\Tests\Unit;

use OCA\Eurooffice\AppConfig;
use OCA\Eurooffice\Cron\EditorsCheck;
use OCA\Eurooffice\DocumentService;
use OCA\Eurooffice\EmailManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Test\TestCase;

/**
 * Covers the settings_error state transitions of the editors availability cron job
 * that do not involve notifying admins.
 */
#[CoversClass(EditorsCheck::class)]
class EditorsCheckTest extends TestCase {

    private AppConfig&MockObject $appConfig;
    private DocumentService&MockObject $documentService;
    private EditorsCheck $job;

    protected function setUp(): void {
        parent::setUp();

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method("linkToRouteAbsolute")->willReturn("https://cloud.example.com/emptyfile");
        $urlGenerator->method("getAbsoluteURL")->willReturn("https://cloud.example.com/");

        $this->appConfig = $this->createMock(AppConfig::class);
        $this->appConfig->method("getDocumentServerUrl")->willReturn("https://docs.example.com/");
        $this->appConfig->method("useDemo")->willReturn(false);
        $this->appConfig->method("getStorageUrl")->willReturn("");
        $this->appConfig->method("getEditorsCheckInterval")->willReturn(3600);

        $this->documentService = $this->createMock(DocumentService::class);

        $this->job = new EditorsCheck(
            $this->createMock(ITimeFactory::class),
            "eurooffice",
            $urlGenerator,
            $this->appConfig,
            $this->createMock(IL10N::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(EmailManager::class),
            $this->createMock(LoggerInterface::class),
            $this->documentService,
        );
    }

    private function runJob(): void {
        (new ReflectionMethod(EditorsCheck::class, "run"))->invoke($this->job, null);
    }

    public function testRecoveryClearsStoredError(): void {
        $this->appConfig->method("settingsAreSuccessful")->willReturn(false);
        $this->documentService->method("checkDocServiceUrl")->willReturn(["", "8.0"]);

        $this->appConfig->expects($this->once())->method("setSettingsError")->with("");

        $this->runJob();
    }

    public function testHealthyCheckLeavesStateUntouched(): void {
        $this->appConfig->method("settingsAreSuccessful")->willReturn(true);
        $this->documentService->method("checkDocServiceUrl")->willReturn(["", "8.0"]);

        $this->appConfig->expects($this->never())->method("setSettingsError");

        $this->runJob();
    }

    public function testRepeatedFailureStillProbesAndUpdatesError(): void {
        $this->appConfig->method("settingsAreSuccessful")->willReturn(false);
        $this->documentService->expects($this->once())->method("checkDocServiceUrl")->willReturn(["Still down", null]);

        $this->appConfig->expects($this->once())->method("setSettingsError")->with("Still down");

        $this->runJob();
    }
}
