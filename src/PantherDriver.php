<?php

declare(strict_types=1);

namespace Lctrs\MinkPantherDriver;

use Behat\Mink\Driver\CoreDriver;
use Behat\Mink\Exception\DriverException;
use Behat\Mink\Exception\UnsupportedDriverActionException;
use Closure;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\Exception\WebDriverException;
use Facebook\WebDriver\Interactions\Internal\WebDriverCoordinates;
use Facebook\WebDriver\Interactions\WebDriverActions;
use Facebook\WebDriver\Internal\WebDriverLocatable;
use Facebook\WebDriver\JavaScriptExecutor;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverCapabilities;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverHasInputDevices;
use Facebook\WebDriver\WebDriverKeys;
use Facebook\WebDriver\WebDriverRadios;
use Facebook\WebDriver\WebDriverSelectInterface;
use LogicException;
use RuntimeException;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Field\FormField;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\DomCrawler\Crawler;
use Symfony\Component\Panther\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Panther\DomCrawler\Field\FileFormField;
use Symfony\Component\Panther\DomCrawler\Field\InputFormField;
use Symfony\Component\Panther\DomCrawler\Field\TextareaFormField;
use function array_merge;
use function chr;
use function count;
use function in_array;
use function is_int;
use function preg_match;
use function preg_replace;
use function rawurlencode;
use function round;
use function sprintf;
use function str_replace;
use function strpos;
use function strtolower;
use function trim;
use function urldecode;
use const PHP_EOL;

final class PantherDriver extends CoreDriver
{
    /** @var Client */
    private $client;
    /** @var bool */
    private $isStarted = false;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * @param list<string>                                                     $arguments
     * @param array{scheme?: string, host?: string, port?: int, path?: string} $options
     */
    public static function createChromeDriver(
        ?string $chromeDriverBinary = null,
        ?array $arguments = null,
        array $options = []
    ) : self {
        return new self(Client::createChromeClient($chromeDriverBinary, $arguments, $options));
    }

    /**
     * @param list<string>                                                     $arguments
     * @param array{scheme?: string, host?: string, port?: int, path?: string} $options
     */
    public static function createFirefoxDriver(
        ?string $geckodriverBinary = null,
        ?array $arguments = null,
        array $options = []
    ) : self {
        return new self(Client::createFirefoxClient($geckodriverBinary, $arguments, $options));
    }

    public static function createSeleniumDriver(
        ?string $host = null,
        ?WebDriverCapabilities $capabilities = null
    ) : self {
        return new self(Client::createSeleniumClient($host, $capabilities));
    }

    public function start() : void
    {
        $this->client->start();

        $this->isStarted = true;
    }

    public function isStarted() : bool
    {
        return $this->isStarted;
    }

    public function stop() : void
    {
        $this->isStarted = false;

        $this->client->quit();
    }

    public function reset() : void
    {
        $this->client->getCookieJar()->clear();
    }

    /**
     * @inheritDoc
     */
    public function visit($url) : void
    {
        $this->client->get($url);
    }

    public function getCurrentUrl() : string
    {
        return $this->client->getCurrentURL();
    }

    public function reload() : void
    {
        $this->client->reload();
    }

    public function forward() : void
    {
        $this->client->forward();
    }

    public function back() : void
    {
        $this->client->back();
    }

    /**
     * @inheritDoc
     */
    public function switchToWindow($name = null) : void
    {
        $this->client->switchTo()->window($name ?? '');
        $this->client->refreshCrawler();
    }

    /**
     * @inheritDoc
     */
    public function switchToIFrame($name = null) : void
    {
        if ($name === null) {
            $this->client->switchTo()->defaultContent();
            $this->client->refreshCrawler();

            return;
        }

        try {
            $this->client->switchTo()->frame(
                $this->client->findElement(WebDriverBy::name($name))
            );
            $this->client->refreshCrawler();
        } catch (WebDriverException $e) {
            throw new DriverException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function setCookie($name, $value = null) : void
    {
        if ($value === null) {
            $this->client->getCookieJar()->expire($name);

            return;
        }

        $this->client->getCookieJar()->set(new Cookie($name, rawurlencode($value)));
    }

    /**
     * @inheritDoc
     */
    public function getCookie($name) : ?string
    {
        $cookie = $this->client->getCookieJar()->get($name);
        if ($cookie === null) {
            return null;
        }

        return urldecode($cookie->getValue());
    }

    public function getContent() : string
    {
        return str_replace(
            ["\r", "\r\n", "\n"],
            PHP_EOL,
            $this->client->getPageSource()
        );
    }

    public function getScreenshot() : string
    {
        return $this->client->takeScreenshot();
    }

    /**
     * @return string[]
     *
     * @psalm-return list<string>
     */
    public function getWindowNames() : array
    {
        return $this->client->getWindowHandles();
    }

    public function getWindowName() : string
    {
        return $this->client->getWindowHandle();
    }

    /**
     * @inheritDoc
     */
    protected function findElementXpaths($xpath) : array
    {
        $elements = $this->getFilteredCrawlerBy($xpath);

        $xPaths = [];
        foreach ($elements as $key => $element) {
            $xPaths[] = sprintf('(%s)[%d]', $xpath, $key+1);
        }

        return $xPaths;
    }

    /**
     * @inheritDoc
     */
    public function getTagName($xpath) : string
    {
        return $this->getFilteredCrawlerBy($xpath)->getTagName();
    }

    /**
     * @inheritDoc
     */
    public function getText($xpath) : string
    {
        return str_replace(
            ["\r", "\r\n", "\n"],
            ' ',
            $this->getFilteredCrawlerBy($xpath)->text()
        );
    }

    /**
     * @inheritDoc
     */
    public function getHtml($xpath) : string
    {
        return $this->getFilteredCrawlerBy($xpath)->getAttribute('innerHTML') ?? '';
    }

    /**
     * @inheritDoc
     */
    public function getOuterHtml($xpath) : string
    {
        return $this->getFilteredCrawlerBy($xpath)->html();
    }

    /**
     * @inheritDoc
     */
    public function getAttribute($xpath, $name) : ?string
    {
        $element = $this->findElement($xpath);

        /**
         * If attribute is present but does not have value, it's considered as Boolean Attributes https://html.spec.whatwg.org/#boolean-attributes
         * but here result may be unexpected in case of <element my-attr/>, my-attr should return TRUE, but it will return "empty string"
         *
         * @see https://w3c.github.io/webdriver/#get-element-attribute
         */
        if (! $this->hasAttribute($element, $name)) {
            return null;
        }

        return $element->getAttribute($name);
    }

    /**
     * @throws UnsupportedDriverActionException
     */
    private function hasAttribute(WebDriverElement $element, string $name) : bool
    {
        return (bool) $this->executeScriptOn($element, 'return arguments[0].hasAttribute(arguments[1]);', $name);
    }

    /**
     * @return string[]|string|bool|null
     *
     * @inheritDoc
     * @psalm-return list<string>|string|bool|null
     */
    public function getValue($xpath)
    {
        $element = $this->findElement($xpath);
        try {
            $formField = $this->getFormField($element);
        } catch (LogicException $e) {
            return $element->getAttribute('value');
        }

        if ($formField instanceof ChoiceFormField) {
            if ($formField->getType() === 'checkbox') {
                return $element->isSelected() ? $element->getAttribute('value') : null;
            }

            if ($formField->getType() === 'radio') {
                $radio = new WebDriverRadios($element);

                try {
                    return $radio->getFirstSelectedOption()->getAttribute('value');
                } catch (NoSuchElementException $e) {
                    return null;
                }
            }

            if (count($formField->availableOptionValues()) === 0) {
                return '';
            }

            $value = $formField->getValue();

            if ($value === '') {
                return null;
            }

            return $value;
        }

        return $formField->getValue();
    }

    /**
     * @inheritDoc
     */
    public function setValue($xpath, $value) : void
    {
        $element = $this->findElement($xpath);

        if ($element->getTagName() === 'input' && in_array($element->getAttribute('type'), ['date', 'color', 'datetime-local', 'month', 'time'], true)) {
                    $this->executeScriptOn(
                        $element,
                        <<<'JS'
if (arguments[0].readOnly) { return; }
if (document.activeElement !== arguments[0]){
    arguments[0].focus();
}
if (arguments[0].value !== arguments[1]) {
    arguments[0].value = arguments[1];
    arguments[0].dispatchEvent(new InputEvent('input'));
    arguments[0].dispatchEvent(new Event('change', { bubbles: true }));
}
JS
                        ,
                        $value
                    );

                    return;
        }

        $field = $this->getFormField($element);

        if ($field instanceof ChoiceFormField && $field->isMultiple()) {
            self::getInnerSelector($field)->deselectAll();
        }

        $field->setValue($value);
        $this->client->getKeyboard()->sendKeys(WebDriverKeys::TAB);
    }

    /**
     * @inheritDoc
     */
    public function check($xpath) : void
    {
        $field = $this->getFormField($this->findElement($xpath));

        if (! $field instanceof ChoiceFormField) {
            throw new DriverException(
                sprintf('Impossible to check the element with XPath "%s" as it is not a checkbox.', $xpath)
            );
        }

        $field->tick();
    }

    /**
     * @inheritDoc
     */
    public function uncheck($xpath) : void
    {
        $field = $this->getFormField($this->findElement($xpath));

        if (! $field instanceof ChoiceFormField) {
            throw new DriverException(
                sprintf('Impossible to uncheck the element with XPath "%s" as it is not a checkbox.', $xpath)
            );
        }

        $field->untick();
    }

    /**
     * @inheritDoc
     */
    public function isChecked($xpath) : bool
    {
        return $this->isSelected($xpath);
    }

    /**
     * @inheritDoc
     */
    public function selectOption($xpath, $value, $multiple = false) : void
    {
        $field = $this->getFormField($this->findElement($xpath));

        if (! $field instanceof ChoiceFormField) {
            throw new DriverException(
                sprintf('Impossible to select an option on the element with XPath "%s" as it is not a select or radio input', $xpath)
            );
        }

        if (! $multiple && $field->isMultiple()) {
            self::getInnerSelector($field)->deselectAll();
        }

        try {
            $field->select($value);
        } catch (NoSuchElementException $e) {
            $selector = self::getInnerSelector($field);

            foreach ((array) $value as $v) {
                $selector->selectByVisibleText($v);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function isSelected($xpath) : bool
    {
        return $this->getFilteredCrawlerBy($xpath)->isSelected();
    }

    /**
     * @inheritDoc
     */
    public function click($xpath) : void
    {
        $this->client->getMouse()->click($this->toCoordinates($xpath));
        $this->client->refreshCrawler();
    }

    /**
     * @inheritDoc
     */
    public function doubleClick($xpath) : void
    {
        $this->client->getMouse()->doubleClick($this->toCoordinates($xpath));
    }

    /**
     * @inheritDoc
     */
    public function rightClick($xpath) : void
    {
        $this->client->getMouse()->contextClick($this->toCoordinates($xpath));
    }

    /**
     * @inheritDoc
     */
    public function attachFile($xpath, $path) : void
    {
        $field = $this->getFormField($this->findElement($xpath));

        if (! $field instanceof FileFormField) {
            throw new DriverException(
                'Impossible to attach a file on the element as it is not a file input'
            );
        }

        $field->setValue($path);
    }

    /**
     * @inheritDoc
     */
    public function isVisible($xpath) : bool
    {
        return $this->getFilteredCrawlerBy($xpath)->isDisplayed();
    }

    /**
     * @inheritDoc
     */
    public function mouseOver($xpath) : void
    {
        $this->client->getMouse()->mouseMove($this->toCoordinates($xpath));
    }

    /**
     * @inheritDoc
     */
    public function focus($xpath) : void
    {
        $this->client->getMouse()->click($this->toCoordinates($xpath));
    }

    /**
     * @inheritDoc
     */
    public function blur($xpath) : void
    {
        $this->executeScriptOn(
            $this->findElement($xpath),
            'arguments[0].focus();arguments[0].blur();'
        );
    }

    /**
     * @param string|null $modifier
     *
     * @inheritDoc
     */
    public function keyPress($xpath, $char, $modifier = null) : void
    {
        $this->focus($xpath);
        $keyboard = $this->client->getKeyboard();

        if ($modifier !== null) {
            $keyboard->sendKeys(self::getModifierKey($modifier));
        }

        $keyboard->sendKeys(self::getCharKey($char));
    }

    /**
     * @param string|null $modifier
     *
     * @inheritDoc
     */
    public function keyDown($xpath, $char, $modifier = null) : void
    {
        $this->focus($xpath);
        $keyboard = $this->client->getKeyboard();

        if ($modifier !== null) {
            $keyboard->pressKey(self::getModifierKey($modifier));
        }

        $keyboard->pressKey(self::getCharKey($char));
    }

    /**
     * @param string|null $modifier
     *
     * @inheritDoc
     */
    public function keyUp($xpath, $char, $modifier = null) : void
    {
        $this->focus($xpath);
        $keyboard = $this->client->getKeyboard();

        if ($modifier !== null) {
            $keyboard->releaseKey(self::getModifierKey($modifier));
        }

        $keyboard->releaseKey(self::getCharKey($char));
    }

    /**
     * @inheritDoc
     */
    public function dragTo($sourceXpath, $destinationXpath) : void
    {
        $this->createWebDriverAction()->dragAndDrop(
            $this->findElement($sourceXpath),
            $this->findElement($destinationXpath)
        )->perform();
    }

    /**
     * @inheritDoc
     */
    public function executeScript($script) : void
    {
        if (preg_match('/^function[\s\(]/', $script) === 1) {
            $script = preg_replace('/;$/', '', $script);
            $script = '(' . $script . ')';
        }

        try {
            $this->client->executeScript($script);
        } catch (WebDriverException $e) {
            throw new DriverException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function evaluateScript($script)
    {
        if (strpos(trim($script), 'return ') !== 0) {
            $script = 'return ' . $script;
        }

        try {
            return $this->client->executeScript($script);
        } catch (WebDriverException $e) {
            throw new DriverException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function wait($timeout, $condition) : bool
    {
        $seconds = (int) round($timeout / 1000.0);
        $wait    = $this->client->wait($seconds);

        $script = 'return ' . $condition . ';';
        /**
         * @return mixed
         */
        $condition = static function (JavaScriptExecutor $driver) use ($script) {
            return $driver->executeScript($script);
        };

        try {
            return (bool) $wait->until($condition);
        } catch (TimeoutException $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function resizeWindow($width, $height, $name = null) : void
    {
        if ($name !== null) {
            throw new UnsupportedDriverActionException('Named windows are not supported.', $this);
        }

        $this->client
            ->manage()
            ->window()
            ->setSize(new WebDriverDimension($width, $height));
    }

    /**
     * @inheritDoc
     */
    public function maximizeWindow($name = null) : void
    {
        if ($name !== null) {
            throw new UnsupportedDriverActionException('Named windows are not supported.', $this);
        }

        $this->client
            ->manage()
            ->window()
            ->maximize();
    }

    /**
     * @inheritDoc
     */
    public function submitForm($xpath) : void
    {
        $this->client->submit(
            $this->getFilteredCrawlerBy($xpath)->form()
        );
        $this->client->refreshCrawler();
    }

    /**
     * @throws DriverException
     */
    private function findElement(string $xpath) : WebDriverElement
    {
        $element = $this->getFilteredCrawlerBy($xpath)->getElement(0);
        if ($element === null) {
            throw new DriverException('The element does not exist');
        }

        return $element;
    }

    /**
     * @throws DriverException
     */
    private function getFilteredCrawlerBy(string $xpath) : Crawler
    {
        return $this->getCrawler()->filterXPath($xpath);
    }

    /**
     * @throws DriverException
     */
    private function getCrawler() : Crawler
    {
        $crawler = $this->client->getCrawler();

        if ($crawler === null) {
            throw new DriverException('Unable to access the response content before visiting a page');
        }

        return $crawler;
    }

    /**
     * @throws DriverException
     */
    private function toCoordinates(string $xpath) : WebDriverCoordinates
    {
        $element = $this->findElement($xpath);

        if (! $element instanceof WebDriverLocatable) {
            throw new RuntimeException(sprintf('The element of "%s" XPath does not implement "%s".', $xpath, WebDriverLocatable::class));
        }

        return $element->getCoordinates();
    }

    private function getFormField(WebDriverElement $element) : FormField
    {
        $tagName = $element->getTagName();

        if ($tagName === 'textarea') {
            return new TextareaFormField($element);
        }

        $type = $element->getAttribute('type');
        if ($tagName === 'select' || ($tagName === 'input' && ($type === 'radio' || $type === 'checkbox'))) {
            return new ChoiceFormField($element);
        }

        if ($tagName === 'input' && $type === 'file') {
            return new FileFormField($element);
        }

        return new InputFormField($element);
    }

    /**
     * @throws UnsupportedDriverActionException
     */
    private function createWebDriverAction() : WebDriverActions
    {
        $webDriver = $this->client->getWebDriver();
        if (! $webDriver instanceof WebDriverHasInputDevices) {
            throw new UnsupportedDriverActionException('This action is not supported by %s.', $this);
        }

        return new WebDriverActions($webDriver);
    }

    /**
     * @param mixed ...$args
     *
     * @return mixed
     *
     * @throws UnsupportedDriverActionException
     */
    private function executeScriptOn(WebDriverElement $element, string $script, ...$args)
    {
        try {
            return $this->client->executeScript($script, array_merge([$element], $args));
        } catch (RuntimeException $e) {
            throw new UnsupportedDriverActionException('JavaScript is not supported by %s.', $this, $e);
        }
    }

    /**
     * @param string|int $char
     */
    private static function getCharKey($char) : string
    {
        if (is_int($char)) {
            $char = strtolower(chr($char));
        }

        return $char;
    }

    /**
     * @throws DriverException
     */
    private static function getModifierKey(string $modifier) : string
    {
        switch ($modifier) {
            case 'alt':
                return WebDriverKeys::ALT;
            case 'ctrl':
                return WebDriverKeys::CONTROL;
            case 'shift':
                return WebDriverKeys::SHIFT;
            case 'meta':
                return WebDriverKeys::META;
            default:
                throw new DriverException(sprintf('Unknown modifier "%s".', $modifier));
        }
    }

    private static function getInnerSelector(ChoiceFormField $field) : WebDriverSelectInterface
    {
        return Closure::bind(static function (ChoiceFormField $field) : WebDriverSelectInterface {
            return $field->selector;
        }, null, ChoiceFormField::class)($field);
    }
}
