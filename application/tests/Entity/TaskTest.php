<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Task;
use PHPUnit\Framework\TestCase;

final class TaskTest extends TestCase
{
    public function testFixedPriceOverridesHourlyCost(): void
    {
        $task = (new Task())->setHours('5.00')->setPrice('99.00');

        self::assertSame(99.0, $task->getCost());
        self::assertFalse($task->isHourly());
    }

    public function testHourlyCostUsesHourlyRate(): void
    {
        $task = (new Task())->setHours('2.50');

        self::assertSame(2.5 * Task::HOURLY_RATE, $task->getCost());
        self::assertTrue($task->isHourly());
    }

    public function testCostIsZeroWithoutHoursOrPrice(): void
    {
        self::assertSame(0.0, (new Task())->getCost());
    }

    public function testDefaultStatusIsOpen(): void
    {
        self::assertSame(Task::STATUS_OPEN, (new Task())->getStatus());
    }

    public function testStatusSlugIsUrlSafe(): void
    {
        $task = (new Task())->setStatus(Task::STATUS_IN_PROGRESS);

        self::assertSame('in-arbeit', $task->getStatusSlug());
    }
}
