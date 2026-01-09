<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use Illuminate\Support\Facades\Log;

class ReceiptController extends Controller
{
    /**
     * Generate/Print receipt for a sale
     */
    public function generate(Sale $sale)
    {
        try {
            $sale->load([
                'customer',
                'cashier',
                'branch.business',
                'items.product',
                'salePayments.payment.paymentMethod'
            ]);

            $receiptData = [
                'receipt_number' => $sale->sale_number,
                'invoice_number' => $sale->invoice_number,
                'company_details' => [
                    'name' => $sale->branch->business->name ?? 'Your Business Name',
                    'branch_name' => $sale->branch->name ?? '',
                    'address' => $sale->branch->address ?? 'Business Address',
                    'phone' => $sale->branch->phone ?? 'Phone Number',
                ],
                'sale_details' => [
                    'date' => $sale->created_at->format('Y-m-d'),
                    'time' => $sale->created_at->format('H:i:s'),
                    'cashier' => $sale->cashier->name ?? 'Unknown',
                    'customer' => $sale->customer ? $sale->customer->name : 'Walk-in Customer',
                ],
                'items' => $sale->items->map(function ($item) {
                    return [
                        'name' => $item->product->name,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'discount' => $item->discount_amount,
                        'tax' => $item->tax_amount,
                        'total' => $item->line_total,
                    ];
                }),
                'payment_details' => [
                    'subtotal' => $sale->subtotal,
                    'tax_amount' => $sale->tax_amount,
                    'discount_amount' => $sale->discount_amount,
                    'total' => $sale->total_amount,
                    'currency' => $sale->currency,
                    'payment_status' => $sale->payment_status,
                    'payment_type' => $sale->payment_type,
                    'payments' => $sale->salePayments->map(function ($sp) {
                        return [
                            'method' => $sp->payment->paymentMethod->name,
                            'amount' => $sp->amount,
                            'reference' => $sp->payment->transaction_id,
                        ];
                    }),
                ],
                'footer_notes' => [
                    'Thank you for your business!',
                    $sale->is_credit_sale ? "Credit Due Date: " . ($sale->credit_due_date ? date('Y-m-d', strtotime($sale->credit_due_date)) : '') : null,
                    'Please come again',
                ],
            ];

            return successResponse('Receipt generated successfully', $receiptData);
        } catch (\Exception $e) {
            Log::error('Failed to generate receipt', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage()
            ]);
            return queryErrorResponse('Failed to generate receipt', $e->getMessage());
        }
    }
}