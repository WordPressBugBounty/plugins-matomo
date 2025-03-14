<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
namespace Piwik\Validators;

use Piwik\Piwik;
class Regex extends \Piwik\Validators\BaseValidator
{
    public function validate($value)
    {
        if ($this->isValueBare($value)) {
            return;
        }
        if (@preg_match($value, '') === \false) {
            throw new \Piwik\Validators\Exception(Piwik::translate('General_ValidatorErrorNoValidRegex', array($value)));
        }
    }
}
