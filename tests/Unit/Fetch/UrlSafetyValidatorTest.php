<?php

namespace Tests\Unit\Fetch;

use App\Exceptions\UnsafeUrlException;
use App\Services\Fetch\UrlSafetyValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UrlSafetyValidatorTest extends TestCase
{
    public static function unsafeUrls(): array
    {
        return [
            'loopback ipv4' => ['http://127.0.0.1/'],
            'loopback name' => ['http://localhost/admin'],
            'private 10' => ['http://10.0.0.1/'],
            'private 192.168' => ['https://192.168.1.50/'],
            'private 172.16' => ['http://172.16.0.1/'],
            'link-local' => ['http://169.254.0.1/'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'ipv6 loopback' => ['http://[::1]/'],
            'unspecified' => ['http://0.0.0.0/'],
            'non-http scheme' => ['ftp://example.com/file'],
            'file scheme' => ['file:///etc/passwd'],
            'credentials in url' => ['http://user:pass@127.0.0.1/'],
            'nonexistent host' => ['https://this-host-does-not-exist.invalid/'],
        ];
    }

    #[Test]
    #[DataProvider('unsafeUrls')]
    public function it_rejects_unsafe_urls(string $url): void
    {
        $this->assertFalse($this->validator()->isSafe($url));

        $this->expectException(UnsafeUrlException::class);
        $this->validator()->validate($url);
    }

    #[Test]
    public function it_allows_a_normal_public_url(): void
    {
        // example.com is IANA-reserved with stable public A records
        $this->assertTrue($this->validator()->isSafe('https://example.com/article'));
    }

    #[Test]
    public function it_honours_the_allowed_hosts_config(): void
    {
        config(['fetch.url_safety.allowed_hosts' => ['localhost']]);

        $this->assertTrue($this->validator()->isSafe('http://localhost/internal'));
    }

    private function validator(): UrlSafetyValidator
    {
        return new UrlSafetyValidator;
    }
}
