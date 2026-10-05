<?php

namespace App\Http\Controllers;

use App\Exceptions\TelegramException;
use App\Models\Order;
use App\Services\CourierSheetService;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class CourierController extends Controller
{
    public function __construct(
        private CourierSheetService $sheets
    ) {
        $this->middleware(['auth', 'tenant', 'tenant.writable']);
    }

    /**
     * GET /courier
     * Courier dispatch queue: status «Отправить», delivery courier.
     */
    public function index(): Response
    {
        $eligibleOrders = $this->eligibleQuery()
            ->get([
                'id',
                'full_name',
                'phone',
                'city',
                'street',
                'building',
                'housing',
                'apartment',
                'poste_restante',
                'goods',
                'quantities',
                'prices',
                'comment',
                'upsell',
                'cross_sell',
                'created_at',
            ]);

        $eligibleOrders->each->append('full_address');

        return Inertia::render('Courier/Index', [
            'eligibleOrders' => $eligibleOrders,
        ]);
    }

    /**
     * GET /courier/orders/{order}/sheet.pdf
     */
    public function sheet(Order $order): HttpResponse
    {
        $this->abortUnlessCourier($order);

        return $this->sheets->download($order);
    }

    /**
     * POST /courier/orders/{order}/telegram
     */
    public function telegram(Order $order): JsonResponse
    {
        $this->abortUnlessCourier($order);

        $telegram = TelegramService::forTenant((int) Auth::user()->tenant_id);
        if ($telegram === null) {
            return $this->configError();
        }

        try {
            $telegram->sendDocument(
                $this->caption($order),
                $this->sheets->pdfBinary($order),
                'courier-' . $order->id . '.pdf'
            );

            return response()->json(['success' => true]);
        } catch (TelegramException $e) {
            return $this->telegramFailure($e->getMessage());
        }
    }

    /**
     * POST /courier/telegram-all
     */
    public function telegramAll(): JsonResponse
    {
        $telegram = TelegramService::forTenant((int) Auth::user()->tenant_id);
        if ($telegram === null) {
            return $this->configError();
        }

        $orders = $this->eligibleQuery()->get();
        $results = [];

        foreach ($orders as $order) {
            try {
                $telegram->sendDocument(
                    $this->caption($order),
                    $this->sheets->pdfBinary($order),
                    'courier-' . $order->id . '.pdf'
                );
                $results[$order->id] = ['success' => true];
            } catch (TelegramException $e) {
                $results[$order->id] = [
                    'success'       => false,
                    'error'         => 'telegram_error',
                    'error_message' => $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                $results[$order->id] = [
                    'success'       => false,
                    'error'         => 'exception',
                    'error_message' => $e->getMessage(),
                ];
            }
        }

        return response()->json(['results' => $results]);
    }

    private function eligibleQuery()
    {
        return Order::query()
            ->where('status', 'Отправить')
            ->where('delivery_type', 'courier')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    private function abortUnlessCourier(Order $order): void
    {
        if ($order->delivery_type !== 'courier') {
            abort(404);
        }

        if ((int) $order->tenant_id !== (int) Auth::user()->tenant_id) {
            abort(404);
        }
    }

    private function caption(Order $order): string
    {
        return 'Курьер · заказ #' . $order->id . ' · ' . $order->full_name;
    }

    private function configError(): JsonResponse
    {
        return response()->json([
            'success'       => false,
            'error'         => 'config_error',
            'error_message' => 'Подключите Telegram в Настройках',
        ], 422);
    }

    private function telegramFailure(string $message): JsonResponse
    {
        return response()->json([
            'success'       => false,
            'error'         => 'telegram_error',
            'error_message' => $message,
        ], 422);
    }
}
