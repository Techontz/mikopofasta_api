<?php

if (! function_exists('money')) {
    /**
     * Format an amount the way the live system does ("1,858,000").
     */
    function money(float|int|string|null $amount): string
    {
        return number_format((float) $amount);
    }
}
