<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ErrorPageTest extends WebTestCase
{
    public function testTheProductionErrorPageRevealsNothing(): void
    {
        $client = self::createClient(['environment' => 'test', 'debug' => false]);
        $client->request('GET', '/vendor/autoload.php');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Page introuvable');
        self::assertStringNotContainsString('Symfony', (string) $client->getResponse()->getContent());
        self::assertResponseHasHeader('Content-Security-Policy');
    }
}
