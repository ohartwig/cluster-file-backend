<?php

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Der Rauchtest: faehrt eine TYPO3-Instanz mit dieser Extension hoch.
 *
 * Er existiert wegen eines Ausfalls. Am 31.07.2026 stand in der Services.yaml
 * einer Extension eine Referenz auf einen Cache-Dienst, den es nicht gab. Der
 * DI-Container liess sich damit nicht bauen, und ein Container, der sich nicht
 * bauen laesst, nimmt nicht einen Dienst aus dem Betrieb, sondern jede Anfrage
 * der Installation. Alle TYPO3-Systeme antworteten mit 500 und leerem Koerper —
 * leer, weil auch die Fehlerseite aus demselben Container kommt.
 *
 * Kein bestehender Test konnte das sehen, und zwar von Bauart her: ein
 * Unit-Test schreibt `new Dienst($a, $b, $c)` und bringt jedes Argument selbst
 * mit. Ob der *Container* sie liefern kann, fragt er nie. Die Verdrahtung ist
 * Code, der ausschliesslich beim Container-Bau ausgefuehrt wird — also testet
 * nur ein Container-Bau sie.
 *
 * Die Aussage dieses Tests steckt deshalb nicht in den Zusicherungen, sondern
 * im Hochfahren: laesst sich der Container nicht bauen, kommt der Lauf gar
 * nicht bis zur ersten Zeile.
 */
final class ContainerWiringTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['moselwal/cluster-file-backend'];

    /**
     * Die Instanz faehrt hoch und der Container steht.
     *
     * Das ist der Test, der den Ausfall gefunden haette — jeder Fehler in der
     * Verdrahtung schlaegt hier zu, unabhaengig davon, welcher Dienst ihn
     * enthaelt.
     */
    #[Test]
    public function theInstanceComesUpWithThisExtensionInstalled(): void
    {
        // Bewusst eine Frage an den Container, keine Typzusicherung auf ihn
        // selbst: `assertInstanceOf(ContainerInterface::class,
        // $this->getContainer())` kann nicht falsch werden, weil die Methode
        // genau diesen Typ zurueckgibt — eine Zusicherung, die nichts zusichert.
        // Dass ein bekannter Kern-Dienst auffindbar ist, sagt dagegen etwas:
        // der Container ist gebaut und beantwortet Anfragen.
        self::assertTrue($this->getContainer()->has(CacheManager::class));
    }

    /**
     * Jeder von Hand verdrahtete Dienst laesst sich bauen.
     *
     * Von Hand verdrahtet heisst: ein Eintrag in der Services.yaml, der eigene
     * `arguments`, eine `factory` oder einen `alias` mitbringt. Genau die
     * werden nicht von der Autowiring-Regel abgedeckt, und genau dort steht
     * das, was jemand getippt hat.
     *
     * Die Liste liest der Test aus der Datei, statt sie zu fuehren. Ein neuer
     * Eintrag ist damit vom ersten Lauf an mitgeprueft — eine gepflegte Liste
     * waere genau dann unvollstaendig, wenn es darauf ankommt.
     *
     * Bewusst eine Schleife und kein Datenlieferant: eine Extension ohne
     * handverdrahtete Dienste ist ein zulaessiger Zustand, ein leerer
     * Datenlieferant in PHPUnit dagegen ein Fehler. Der Test haette dort
     * verlaesslich rot gemeldet, ohne dass etwas kaputt ist — und ein Gate,
     * das ohne Anlass rot wird, wird abgeschaltet.
     */
    #[Test]
    public function everyHandWiredServiceCanBeBuilt(): void
    {
        $services = self::handWiredServices();

        if ([] === $services) {
            self::markTestSkipped('diese Extension verdrahtet keinen Dienst von Hand');
        }

        foreach ($services as $id) {
            self::assertInstanceOf($id, $this->get($id), $id);
        }
    }

    /**
     * @return list<class-string>
     */
    private static function handWiredServices(): array
    {
        // PARSE_CONSTANT fuer `!php/const`, PARSE_CUSTOM_TAGS, damit ein
        // kuenftiges unbekanntes Tag den Test nicht zum Einsturz bringt,
        // sondern nur diesen einen Eintrag unlesbar macht.
        $parsed = Yaml::parseFile(
            // sprintf statt Verkettung: die Regel concat_space steht in den
            // Repositories unterschiedlich, je nachdem ob der Hausstandard
            // schon angewendet ist. Ohne Verkettung greift sie gar nicht, und
            // dieselbe Datei passt in beiden Faellen.
            \sprintf('%s/Configuration/Services.yaml', \dirname(__DIR__, 2)),
            Yaml::PARSE_CONSTANT | Yaml::PARSE_CUSTOM_TAGS,
        );

        /** @var array<string, mixed> $services */
        $services = $parsed['services'] ?? [];
        $found = [];

        foreach ($services as $id => $definition) {
            if (!\is_string($id) || !\is_array($definition)) {
                continue;
            }

            // Namensraum-Regeln (`Foo\Bar\:` mit `resource:`) beschreiben keine
            // einzelne Klasse; `_defaults` und `_instanceof` erst recht nicht.
            if (!class_exists($id) && !interface_exists($id)) {
                continue;
            }

            if ([] === array_intersect(['arguments', 'factory', 'alias'], array_keys($definition))) {
                continue;
            }

            $found[] = $id;
        }

        return $found;
    }
}
