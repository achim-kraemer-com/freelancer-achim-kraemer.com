<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTimerTest extends TestCase
{
    public function testNewUserHasNoRunningTimer(): void
    {
        $user = new User();

        self::assertFalse($user->isTimerRunning());
        self::assertSame(0, $user->getElapsedSeconds());
    }

    public function testStartTimerMarksItRunning(): void
    {
        $user = (new User())->startTimer();

        self::assertTrue($user->isTimerRunning());
    }

    public function testElapsedSecondsIncludeRunningInterval(): void
    {
        $user = (new User())->setTimerStartedAt(new \DateTimeImmutable('-60 seconds'));

        self::assertGreaterThanOrEqual(60, $user->getElapsedSeconds());
        self::assertLessThan(65, $user->getElapsedSeconds());
    }

    public function testStopTimerFoldsIntervalIntoAccumulatedSeconds(): void
    {
        $user = (new User())->setTimerStartedAt(new \DateTimeImmutable('-60 seconds'));
        $user->stopTimer();

        self::assertFalse($user->isTimerRunning());
        self::assertGreaterThanOrEqual(60, $user->getTimerAccumulatedSeconds());
    }

    public function testResetClearsTimer(): void
    {
        $user = (new User())->setTimerStartedAt(new \DateTimeImmutable('-60 seconds'));
        $user->stopTimer();
        $user->resetTimer();

        self::assertFalse($user->isTimerRunning());
        self::assertSame(0, $user->getTimerAccumulatedSeconds());
        self::assertSame(0, $user->getElapsedSeconds());
    }
}
