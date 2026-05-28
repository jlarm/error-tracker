<?php

declare(strict_types=1);

namespace Armp\ErrorTracker\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void capture(\Throwable $e, ?\Illuminate\Http\Request $request = null, ?string $level = null)
 * @method static void message(string $message, string $level = 'info', array $context = [])
 * @method static \Armp\ErrorTracker\ErrorTracker tag(string $key, string|int|float|bool|null $value)
 * @method static \Armp\ErrorTracker\ErrorTracker context(string $name, array $values)
 * @method static \Armp\ErrorTracker\ErrorTracker user(array $user)
 * @method static \Armp\ErrorTracker\ErrorTracker breadcrumb(string $category, string $message, array $data = [], string $level = 'info')
 * @method static \Armp\ErrorTracker\ErrorTracker reset()
 *
 * @see \Armp\ErrorTracker\ErrorTracker
 */
class ErrorTracker extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Armp\ErrorTracker\ErrorTracker::class;
    }
}
