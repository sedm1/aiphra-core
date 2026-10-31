<?php

namespace AiphraCore\Providers\Concerns;

use AiphraCore\Exceptions\UnsupportedOperationException;
use AiphraCore\Method;
use AiphraCore\Objects\Calendars\{Calendar, Calendars};
use Closure;

/**
 * По умолчанию используется CalDAV; setMethod выбирает реализацию операции.
 * @internal
 */
trait SelectTransport {
    private Method $method = Method::CalDav;

    final public function setMethod(Method $method): static {
        $this->method = $method;
        return $this;
    }

    /**
     * @return Closure(): (Calendar|Calendars)
     */
    private function operationHandler(string $operation): Closure {
        return $this->operations($this->method)[$operation]
            ?? throw new UnsupportedOperationException('Provider does not implement ' . $operation . ' via ' . $this->method->value . '.');
    }
}
