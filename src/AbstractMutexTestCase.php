<?php

declare(strict_types=1);

namespace WyriHaximus\React\Mutex;

use PHPUnit\Framework\Attributes\Test;
use React\Promise\PromiseInterface;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;
use WyriHaximus\React\Mutex\Contracts\LockInterface;
use WyriHaximus\React\Mutex\Contracts\MutexInterface;

use function bin2hex;
use function random_bytes;
use function React\Async\await;
use function React\Promise\all;
use function time;
use function WyriHaximus\React\timedPromise;

abstract class AbstractMutexTestCase extends AsyncTestCase
{
    abstract public function provideMutex(): MutexInterface;

    #[Test]
    final public function thatYouCantRequiredTheSameLockTwice(): void
    {
        $key = $this->generateKey();

        $mutex = $this->provideMutex();

        $firstLock  = '';
        $secondLock = '';

        $firstMutexPromise = $mutex->acquire($key, 2.0);
        /** @phpstan-ignore ergebnis.noParameterWithNullableTypeDeclaration */
        $firstMutexPromise->then(static function (LockInterface|null $lock) use (&$firstLock): void {
            $firstLock = $lock;
        });
        $secondtMutexPromise = timedPromise(1)->then(
            static fn (): PromiseInterface => $mutex->acquire($key, 2.0),
        );
        /** @phpstan-ignore ergebnis.noParameterWithNullableTypeDeclaration */
        $secondtMutexPromise->then(static function (LockInterface|null $lock) use (&$secondLock): void {
            $secondLock = $lock;
        });

        await(all([$firstMutexPromise, $secondtMutexPromise]));

        self::assertInstanceOf(LockInterface::class, $firstLock);
        self::assertNull($secondLock);
    }

    #[Test]
    final public function cannotReleaseLockWithWrongRng(): void
    {
        $key = $this->generateKey();

        $mutex = $this->provideMutex();

        $fakeLock = new LockStub($key, 'rng');

        $mutex->acquire($key, 1.0);

        $result = await($mutex->release($fakeLock));
        self::assertFalse($result);
    }

    #[Test]
    final public function spinWillWaiUntil(): void
    {
        $spinAcquireReleaseTime = null;
        $lockReleaseTime        = null;

        $key   = $this->generateKey();
        $mutex = $this->provideMutex();

        $lock = await($mutex->acquire($key, 1.0 * 100));
        self::assertInstanceOf(LockInterface::class, $lock);

        /** @phpstan-ignore ergebnis.noParameterWithNullableTypeDeclaration */
        $spinPromise = $mutex->spin($key, 1.0, 13, 3)->then(static function (LockInterface|null $lock) use (&$spinAcquireReleaseTime): LockInterface {
            $spinAcquireReleaseTime = time();

            self::assertInstanceOf(LockInterface::class, $lock);

            return $lock;
        });

        $releasePromise = timedPromise(0.1)->then(static function () use (&$lockReleaseTime, $mutex, $lock): PromiseInterface {
            $lockReleaseTime = time();

            return $mutex->release($lock);
        });

        $result   = await($releasePromise);
        $spinLock = await($spinPromise);

        self::assertTrue($result);
        self::assertSame($key, $spinLock->key());
        self::assertNotNull($spinAcquireReleaseTime, 'Spin');
        self::assertNotNull($lockReleaseTime, 'Aquire');
        self::assertGreaterThan($lockReleaseTime, $spinAcquireReleaseTime);
    }

    #[Test]
    final public function spinDoesNotLock(): void
    {
        $key   = $this->generateKey();
        $mutex = $this->provideMutex();

        $lock = await($mutex->acquire($key, 1.0 * 100));
        self::assertInstanceOf(LockInterface::class, $lock);

        $spinPromise = $mutex->spin($key, 1.0, 3, 0.001);

        $releasePromise = timedPromise(0.1)->then(static fn (): PromiseInterface => $mutex->release($lock));

        [$result, $spinLock] = await(all([$releasePromise, $spinPromise]));

        self::assertTrue($result);
        self::assertNull($spinLock);
    }

    private function generateKey(): string
    {
        return 'key-' . time() . '-' . bin2hex(random_bytes(2));
    }
}
