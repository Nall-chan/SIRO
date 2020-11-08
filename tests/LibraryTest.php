<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

class LibraryValidationTest extends TestCaseSymconValidation
{
    public function testValidateLibrary(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidateSIRORollerBlind(): void
    {
        $this->validateModule(__DIR__ . '/../SIRO Roller blind');
    }

    public function testValidateSIROSplitter(): void
    {
        $this->validateModule(__DIR__ . '/../SIRO Splitter');
    }

    public function testValidateSIROConfigurator(): void
    {
        $this->validateModule(__DIR__ . '/../SIRO Configurator');
    }
}  