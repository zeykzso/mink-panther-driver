<?php

declare(strict_types=1);

namespace Lctrs\MinkPantherDriver\Test\Custom;

use Behat\Mink\Tests\Driver\Js\EventsTest as MinkEventsTest;

final class EventsTest extends MinkEventsTest
{
    /**
     * @inheritDoc
     * @dataProvider provideKeyboardEventsModifiers
     */
    public function testKeyboardEvents($modifier, $eventProperties) : void
    {
        $this->getSession()->visit($this->pathTo('/js_test.html'));
        $webAssert = $this->getAssertSession();

        $input1 = $webAssert->elementExists('css', '.elements input.input.first');
        $input2 = $webAssert->elementExists('css', '.elements input.input.second');
        $input3 = $webAssert->elementExists('css', '.elements input.input.third');
        $event  = $webAssert->elementExists('css', '.elements .text-event');

        $input1->keyDown('u', $modifier);
        self::assertEquals('key downed:' . $eventProperties, $event->getText());

        $input2->keyPress('r', $modifier);
        if ($modifier === 'shift') {
            self::assertEquals('key pressed:82 / ' . $eventProperties, $event->getText());
        } else {
            self::assertEquals('key pressed:114 / ' . $eventProperties, $event->getText());
        }

        $input3->keyUp(85, $modifier);
        self::assertEquals('key upped:85 / ' . $eventProperties, $event->getText());
    }
}
