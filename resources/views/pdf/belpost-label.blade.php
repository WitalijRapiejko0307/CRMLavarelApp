@php
    /** @var array<string, mixed> $label */
    $senderPostcode    = trim((string) ($label['sender_postcode'] ?? ''));
    $senderCity        = trim((string) ($label['sender_city'] ?? ''));
    $recipientPostcode = trim((string) ($label['recipient_postcode'] ?? ''));
    $recipientCity     = trim((string) ($label['recipient_city'] ?? ''));
    $logoPath          = (string) ($label['ecommerce_logo_path'] ?? '');
    $hasLogo           = !empty($label['show_ecommerce_logo']) && $logoPath !== '';
    $hasType           = !empty($label['package_title']);
@endphp
<table class="label-card">
    <colgroup>
        <col class="col-left">
        <col class="col-right">
    </colgroup>
    <tr class="row-head">
        <td class="col-left">
            <div class="from-name">{{ $label['sender_name'] }}</div>
            <div>{{ $label['sender_street'] }}</div>
            <div>
                @if ($senderPostcode !== '')
                    <strong>{{ $senderPostcode }}</strong>@if ($senderCity !== ''), @endif
                @endif{{ $senderCity }}
            </div>
        </td>
        <td class="col-right">
            <div class="contract">
                Оплачено по договору № {{ $label['contract_no'] }} от {{ $label['contract_date'] }}
            </div>
            <div class="cod">
                Наложенный платеж <strong>{{ $label['cod_rubles'] }} руб. {{ $label['cod_kopecks'] }} коп.</strong>
            </div>
            <div class="shelf">
                Срок хранения - <strong>{{ $label['shelf_life'] }} дней</strong>
            </div>
        </td>
    </tr>
    <tr class="row-brand">
        <td class="col-left">
            <table class="brand-row @if ($hasLogo && $hasType) brand-pair @endif">
                <tr>
                    @if ($hasLogo)
                        <td class="brand-logo">
                            <img src="{{ $logoPath }}" alt="e-commerce">
                        </td>
                    @endif
                    @if ($hasType)
                        <td class="brand-type">{{ $label['package_title'] }}</td>
                    @endif
                </tr>
            </table>
        </td>
        <td class="col-right brand-spacer"></td>
    </tr>
    <tr class="row-body">
        <td class="col-left">
            <div class="weight">{{ $label['weight_text'] }}</div>
            @if (!empty($label['show_recipient_pays']))
                <div class="tick">&#10003; Оплата за пересылку при вручении</div>
            @endif
            @if (!empty($label['show_electronic_notice']))
                <div class="tick">&#10003; Электронное уведомление</div>
            @endif
        </td>
        <td class="col-right">
            <div class="barcode-box">
                @if (!empty($label['barcode_markup']))
                    {!! $label['barcode_markup'] !!}
                @endif
                <div class="track">{{ $label['track_number'] }}</div>
            </div>
            <div class="to-block">
                <div class="to-name">{{ $label['recipient_name'] }}</div>
                <div>{{ $label['recipient_street'] }}</div>
                <div>
                    @if ($recipientPostcode !== '')
                        <strong>{{ $recipientPostcode }}</strong>@if ($recipientCity !== ''), @endif
                    @endif{{ $recipientCity }}
                </div>
                <div>{{ $label['recipient_phone'] }}</div>
            </div>
        </td>
    </tr>
</table>
