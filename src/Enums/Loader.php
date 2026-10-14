<?php

namespace GiacomoMasseroni\AsyncColumn\Enums;

/**
 * The built-in, pure-CSS loaders an AsyncColumn can show while its cells
 * resolve. Each case's value is the suffix of its CSS class
 * (.fi-async-column-{value}) in resources/css/async-column.css.
 */
enum Loader: string
{
    case Skeleton = 'skeleton';
    case Spinner = 'spinner';
    case Dots = 'dots';
    case Bar = 'bar';
}
