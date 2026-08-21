<?php

namespace App\Tools\Dummy;

use App\Tools\Contracts\Tool;
use Illuminate\Support\Str;

class GetWeatherMock implements Tool
{
    private const CONDITIONS = ['sunny', 'cloudy', 'rainy', 'windy'];

    public function name(): string
    {
        return 'get_weather_mock';
    }

    public function description(): string
    {
        return 'Returns mock weather data for a city. Use whenever the user asks about weather anywhere.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'city' => ['type' => 'string', 'description' => 'City name'],
            ],
            'required' => ['city'],
        ];
    }

    /**
     * Deterministic per city: same input always yields the same condition
     * and temperature, so eval assertions are stable.
     *
     * @param  array<string, mixed>  $args
     * @return array{city: string, condition: string, temperature_c: float}
     */
    public function execute(array $args): array
    {
        $city = trim((string) ($args['city'] ?? ''));

        if ($city === '') {
            throw new \InvalidArgumentException('get_weather_mock requires a non-empty city.');
        }

        $seed = crc32(Str::lower($city));

        return [
            'city' => $city,
            'condition' => self::CONDITIONS[$seed % count(self::CONDITIONS)],
            'temperature_c' => ($seed % 350) / 10, // 0.0 - 34.9 °C, deterministic
        ];
    }
}
