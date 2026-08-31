<?php

function fifteenSigDigitsWithExponent(): bool
{
    return is_float(1.23456789012345e300);
}

function trailingZerosAreNotSignificant(): bool
{
    return is_float(999999999999999.0);
}

function roundTripSixteenDigits(): bool
{
    return is_float(2.220446049250313E-16);
}

function hexLiteralStaysNumeric(): float
{
    return 0x123456789E1234567;
}

function autoDecimalKeepsPromotion()
{
    return 3.14159265358979323846;
}

function decimalLiteralDemotesAgainstFloat(float $f): bool
{
    return $f == 3.14159265358979323846;
}
