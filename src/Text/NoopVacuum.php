<?php

namespace Moaines\IllumiSearch\Text;

/**
 * Shared stub for engines without VACUUM support.
 */
trait NoopVacuum
{
    public function vacuum(): void {}
}
