<?php

declare(strict_types=1);

use BillTo\Shop\RefundLines;

it('builds remaining quantities, cumulative across refunds', function () {
    $lineMap = ['10' => 1, '20' => 2, '30' => 3];

    $lines = RefundLines::remaining($lineMap, [
        ['id' => '10', 'originalQuantity' => 5.0, 'refundedQuantity' => 3.0, 'isProduct' => true],
        ['id' => '20', 'originalQuantity' => 1.0, 'refundedQuantity' => 1.0, 'isProduct' => false],
        ['id' => '99', 'originalQuantity' => 1.0, 'refundedQuantity' => 1.0, 'isProduct' => true],
        ['id' => '30', 'originalQuantity' => 2.0, 'refundedQuantity' => 0.0, 'isProduct' => true],
    ]);

    expect($lines)->toBe([
        ['line_number' => 1, 'quantity' => 2.0],
        ['line_number' => 2, 'quantity' => 0.0],
    ]);
});

it('turns an amount-only refund into a proportional price reduction', function () {
    $lineMap = ['10' => 1, '20' => 2];
    $lines = [
        ['id' => '10', 'quantity' => 2.0, 'netTotal' => 190.0],
        ['id' => '20', 'quantity' => 1.0, 'netTotal' => 10.0],
    ];

    expect(RefundLines::proportional($lineMap, $lines, 24.60, 246.0))->toBe([
        ['line_number' => 1, 'after_unit_price' => 85.5],
        ['line_number' => 2, 'after_unit_price' => 9.0],
    ])
        ->and(RefundLines::proportional($lineMap, $lines, 246.0, 246.0))->toBe([])
        ->and(RefundLines::proportional($lineMap, $lines, 0.0, 246.0))->toBe([]);
});
