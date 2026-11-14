<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Qr;

/**
 * The screen master: one SVG path of unit squares on a white square (UAMS-Web/aether-redirects#14).
 */
final class SvgWriter implements QrWriter
{
    public function extension(): string
    {
        return 'svg';
    }

    public function write(QrMatrix $matrix): string
    {
        $total = $matrix->total();

        $squares = array_map(
            static fn (array $module): string => sprintf('M%d %dh1v1h-1z', $module[0], $module[1]),
            $matrix->darkModules(),
        );

        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" shape-rendering="crispEdges">'
            .'<rect width="%1$d" height="%1$d" fill="#fff"/><path fill="#000" d="%2$s"/></svg>'."\n",
            $total,
            implode('', $squares),
        );
    }
}
