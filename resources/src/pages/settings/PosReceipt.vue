<template>
  <div class="page">
    <PageHeader :title="$t('POS_Receipt')" :breadcrumb="[$t('Settings'), $t('POS_Receipt')]" />

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <!-- Layout + live preview -->
      <a-card style="margin-bottom: 16px">
        <template #title>{{ $t('POS_receipt_layout_default') }}</template>
        <template #extra>
          <a-button size="small" @click="printPosDemo">
            <PrinterOutlined /> {{ $t('print') }}
          </a-button>
        </template>

        <div style="display: flex; justify-content: center; margin-bottom: 20px">
          <a-segmented
            v-model:value="pos_settings.receipt_layout"
            :options="[
              { value: 1, label: $t('Layout_1_Standard') },
              { value: 2, label: $t('Layout_2_Compact') },
              { value: 3, label: $t('Layout_3_Detailed') },
              { value: 4, label: $t('Layout_4_Bilingual') },
              { value: 5, label: $t('Layout_5_Minimal') },
            ]"
          />
        </div>

        <!-- Live receipt demo. Markup is VERBATIM from legacy pos_receipt.vue:
             printing copies #pos-receipt-demo innerHTML into a popup styled by
             /css/pos_print.css, so classes/structure must match real receipts. -->
                      <div class="pos-receipt-demo" id="pos-receipt-demo" :style="previewFontStyle">
                        <!-- Layout 1 demo (Standard) -->
                        <div v-if="currentReceiptLayout === 1" class="receipt-layout-1">
                          <div class="info text-center mb-2">
                            <div class="invoice_logo mb-1" v-show="pos_settings.show_logo !== 0">
                              <div class="demo-logo-circle">LOGO</div>
                            </div>
                            <div v-show="pos_settings.show_store_name !== 0">Demo Store</div>
                            <small v-show="pos_settings.show_reference !== 0">Ref: REF-12345</small><br v-show="pos_settings.show_reference !== 0">
                            <small v-show="pos_settings.show_address">123 Demo Street</small><br v-show="pos_settings.show_address">
                            <small v-show="pos_settings.show_phone">+123 456 789</small><br v-show="pos_settings.show_phone">
                            <small v-show="pos_settings.show_email">demo@example.com</small>
                            <div class="mt-2">
                              <small v-show="pos_settings.show_date !== 0">Date: 2025-12-10 12:34</small><br>
                              <small v-show="pos_settings.show_seller !== 0">Seller: John Doe</small><br>
                              <small v-show="pos_settings.show_customer">Customer: Jane Smith</small><br>
                              <small v-show="pos_settings.show_Warehouse">Warehouse: Main Store</small>
                            </div>
                          </div>
                          <table class="table_data w-100 mb-2" style="font-size:11px;">
                            <tbody>
                              <tr>
                                <td colspan="3">
                                  Demo Product A<br>
                                  <small>2 x 10.00</small>
                                  <br v-show="pos_settings.show_product_discount !== 0">
                                  <small v-show="pos_settings.show_product_discount !== 0" style="color:#888;font-style:italic;">Discount: -2.00</small>
                                </td>
                                <td style="text-align:right;">20.00</td>
                              </tr>
                              <tr>
                                <td colspan="3">
                                  Demo Product B<br>
                                  <small>1 x 5.00</small>
                                </td>
                                <td style="text-align:right;">5.00</td>
                              </tr>
                            </tbody>
                          </table>
                          <table class="table_data w-100" style="font-size:11px;">
                            <tbody>
                              <tr>
                                <td class="total">Total</td>
                                <td style="text-align:right;" class="total">25.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_paid !== 0">
                                <td class="total">Paid</td>
                                <td style="text-align:right;" class="total">20.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_due !== 0">
                                <td class="total">Due</td>
                                <td style="text-align:right;" class="total">5.00</td>
                              </tr>
                            </tbody>
                          </table>
                          <table
                            class="table_data w-100 mt-1"
                            style="font-size:11px;"
                            v-show="pos_settings.show_payments !== 0"
                          >
                            <thead>
                              <tr>
                                <th style="text-align:left;">Pay By</th>
                                <th style="text-align:right;">Amount</th>
                                <th style="text-align:right;">Change</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr>
                                <td>Cash</td>
                                <td style="text-align:right;">20.00</td>
                                <td style="text-align:right;">0.00</td>
                              </tr>
                            </tbody>
                          </table>
                          <p class="mt-2 mb-0 text-center" v-show="pos_settings.show_note" style="white-space:pre-line;">
                            <small><strong>{{ pos_settings.note_customer || 'Thank you for your purchase!' }}</strong></small>
                          </p>
                          <div class="mt-2 text-center" v-show="pos_settings.show_zatca_qr !== 0">
                            <div class="zatca-qr">
                              <div class="zatca-qr-title">ZATCA</div>
                              <div class="demo-qr-box"></div>
                            </div>
                          </div>
                          <!-- Barcode from Ref -->
                          <div v-if="pos_settings.show_barcode !== 0" class="mt-2 text-center">
                            <BarcodeSvg
                              value="REF-12345"
                              format="CODE128"
                              textmargin="0"
                              fontSize="12"
                              height="40"
                              width="1"
                             />
                          </div>
                        </div>

                        <!-- Layout 2 demo (Compact) -->
                        <div v-else-if="currentReceiptLayout === 2" class="receipt-layout-2">
                          <div class="info text-center mb-2">
                            <div class="demo-logo-circle small mb-1" v-show="pos_settings.show_logo !== 0">
                              LOGO
                            </div>
                            <div v-show="pos_settings.show_store_name !== 0">Demo Store</div>
                            <small v-show="pos_settings.show_reference !== 0">Ref: REF-12345</small><br v-show="pos_settings.show_reference !== 0">
                            <small v-show="pos_settings.show_address">123 Demo Street</small><br v-show="pos_settings.show_address">
                            <small v-show="pos_settings.show_phone">+123 456 789</small><br v-show="pos_settings.show_phone">
                            <small v-show="pos_settings.show_email">demo@example.com</small>
                            <div class="mt-1">
                              <small v-show="pos_settings.show_date !== 0">Date: 2025-12-10 12:34</small><br>
                              <small v-show="pos_settings.show_seller !== 0">Seller: John Doe</small><br>
                              <small v-show="pos_settings.show_customer">Customer: Jane Smith</small><br>
                              <small v-show="pos_settings.show_Warehouse">Warehouse: Main Store</small>
                            </div>
                          </div>
                          <table class="table_data w-100 mb-2" style="font-size:11px;">
                            <thead>
                              <tr>
                                <th style="text-align:left;">Item</th>
                                <th style="text-align:center;">Qty</th>
                                <th style="text-align:right;">Price</th>
                                <th style="text-align:right;">Total</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr>
                                <td>Demo A</td>
                                <td style="text-align:center;">2</td>
                                <td style="text-align:right;">10.00</td>
                                <td style="text-align:right;">20.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_product_discount !== 0">
                                <td colspan="4" style="color:#888;font-style:italic;font-size:10px;padding-left:8px;">Discount: -2.00</td>
                              </tr>
                              <tr>
                                <td>Demo B</td>
                                <td style="text-align:center;">1</td>
                                <td style="text-align:right;">5.00</td>
                                <td style="text-align:right;">5.00</td>
                              </tr>
                            </tbody>
                          </table>
                          <table class="table_data w-100" style="font-size:11px;">
                            <tbody>
                              <tr v-show="pos_settings.show_tax">
                                <td class="total">Tax</td>
                                <td style="text-align:right;" class="total">1.25</td>
                              </tr>
                              <tr v-show="pos_settings.show_discount">
                                <td class="total">Discount</td>
                                <td style="text-align:right;" class="total">0.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_shipping">
                                <td class="total">Shipping</td>
                                <td style="text-align:right;" class="total">1.25</td>
                              </tr>
                              <tr>
                                <td class="total">Total</td>
                                <td style="text-align:right;" class="total">25.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_paid !== 0">
                                <td class="total">Paid</td>
                                <td style="text-align:right;" class="total">20.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_due !== 0">
                                <td class="total">Due</td>
                                <td style="text-align:right;" class="total">5.00</td>
                              </tr>
                            </tbody>
                          </table>
                          <table
                            class="table_data w-100 mt-1"
                            style="font-size:11px;"
                            v-show="pos_settings.show_payments !== 0"
                          >
                            <thead>
                              <tr>
                                <th style="text-align:left;">Pay By</th>
                                <th style="text-align:right;">Amount</th>
                                <th style="text-align:right;">Change</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr>
                                <td>Cash</td>
                                <td style="text-align:right;">20.00</td>
                                <td style="text-align:right;">0.00</td>
                              </tr>
                            </tbody>
                          </table>
                          <p class="mt-2 mb-0 text-center" v-show="pos_settings.show_note" style="white-space:pre-line;">
                            <small><strong>{{ pos_settings.note_customer || 'Thank you for your purchase!' }}</strong></small>
                          </p>
                          <div class="mt-2 text-center" v-show="pos_settings.show_zatca_qr !== 0">
                            <div class="zatca-qr">
                              <div class="zatca-qr-title">ZATCA</div>
                              <div class="demo-qr-box"></div>
                            </div>
                          </div>
                          <!-- Barcode from Ref -->
                          <div v-if="pos_settings.show_barcode !== 0" class="mt-2 text-center">
                            <BarcodeSvg
                              value="REF-12345"
                              format="CODE128"
                              textmargin="0"
                              fontSize="12"
                              height="40"
                              width="1"
                             />
                          </div>
                        </div>

                        <!-- Layout 3 demo (Detailed) -->
                        <div v-else-if="currentReceiptLayout === 3" class="receipt-layout-3">
                          <div class="info mb-2">
                            <div class="d-flex justify-content-between">
                              <div>
                                <strong v-show="pos_settings.show_store_name !== 0">Demo Store</strong><br>
                                <small v-show="pos_settings.show_reference !== 0">Ref: REF-12345</small><br v-show="pos_settings.show_reference !== 0">
                                <small v-show="pos_settings.show_address">123 Demo Street</small><br>
                                <small v-show="pos_settings.show_phone">+123 456 789</small>
                              </div>
                              <div class="demo-logo-rect" v-show="pos_settings.show_logo !== 0">LOGO</div>
                            </div>
                            <div class="mt-2" style="font-size:11px;">
                              <div v-show="pos_settings.show_date !== 0">Date: 2025-12-10 12:34</div>
                              <div v-show="pos_settings.show_seller !== 0">Seller: John Doe</div>
                              <div v-show="pos_settings.show_customer">Customer: Jane Smith</div>
                              <div v-show="pos_settings.show_Warehouse">Warehouse: Main Store</div>
                            </div>
                          </div>
                          <table class="table_data w-100 mb-2" style="font-size:11px;">
                            <tbody>
                              <tr>
                                <td>
                                  <strong>Demo Product A</strong><br>
                                  <small>2 x 10.00</small>
                                  <br v-show="pos_settings.show_product_discount !== 0">
                                  <small v-show="pos_settings.show_product_discount !== 0" style="color:#888;font-style:italic;">Discount: -2.00</small>
                                </td>
                                <td style="text-align:right;">20.00</td>
                              </tr>
                              <tr>
                                <td>
                                  <strong>Demo Product B</strong><br>
                                  <small>1 x 5.00</small>
                                </td>
                                <td style="text-align:right;">5.00</td>
                              </tr>
                            </tbody>
                          </table>
                          <table class="table_data w-100" style="font-size:11px;">
                            <tbody>
                              <tr v-show="pos_settings.show_tax">
                                <td class="total">Tax</td>
                                <td style="text-align:right;" class="total">1.25</td>
                              </tr>
                              <tr v-show="pos_settings.show_discount">
                                <td class="total">Discount</td>
                                <td style="text-align:right;" class="total">0.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_shipping">
                                <td class="total">Shipping</td>
                                <td style="text-align:right;" class="total">1.25</td>
                              </tr>
                              <tr>
                                <td class="total">Total</td>
                                <td style="text-align:right;" class="total">26.25</td>
                              </tr>
                              <tr v-show="pos_settings.show_paid !== 0">
                                <td class="total">Paid</td>
                                <td style="text-align:right;" class="total">25.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_due !== 0">
                                <td class="total">Due</td>
                                <td style="text-align:right;" class="total">1.25</td>
                              </tr>
                            </tbody>
                          </table>
                          <table
                            class="table_data w-100 mt-1"
                            style="font-size:11px;"
                            v-show="pos_settings.show_payments !== 0"
                          >
                            <thead>
                              <tr>
                                <th style="text-align:left;">Pay By</th>
                                <th style="text-align:right;">Amount</th>
                                <th style="text-align:right;">Change</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr>
                                <td>Cash</td>
                                <td style="text-align:right;">25.00</td>
                                <td style="text-align:right;">0.00</td>
                              </tr>
                            </tbody>
                          </table>
                          <p class="mt-2 mb-0 text-center" v-show="pos_settings.show_note" style="white-space:pre-line;">
                            <small><strong>{{ pos_settings.note_customer || 'Thank you for your purchase!' }}</strong></small>
                          </p>
                          <div class="mt-2 text-center" v-show="pos_settings.show_zatca_qr !== 0">
                            <div class="zatca-qr">
                              <div class="zatca-qr-title">ZATCA</div>
                              <div class="demo-qr-box"></div>
                            </div>
                          </div>
                          <!-- Barcode from Ref -->
                          <div v-if="pos_settings.show_barcode !== 0" class="mt-2 text-center">
                            <BarcodeSvg
                              value="REF-12345"
                              format="CODE128"
                              textmargin="0"
                              fontSize="12"
                              height="40"
                              width="1"
                             />
                          </div>
                        </div>

                        <!-- Layout 4 demo (Bilingual AR+EN) -->
                        <div v-else-if="currentReceiptLayout === 4" class="receipt-layout-4">
                          <div class="info text-center mb-2">
                            <div class="invoice_logo mb-1" v-show="pos_settings.show_logo !== 0">
                              <div class="demo-logo-circle">LOGO</div>
                            </div>
                            <div>
                              <strong style="font-size:13px;">متجر تجريبي</strong><br>
                              <strong style="font-size:12px;">Demo Store</strong>
                            </div>
                            <div style="font-size:10px;margin-top:2px;">123 Demo Street</div>
                            <div style="font-size:10px;">+123 456 789</div>
                            <div v-show="pos_settings.show_email" style="font-size:10px;">demo@example.com</div>
                            <div v-if="setting.vat_number" style="font-size:11px;font-weight:bold;margin-top:4px;">
                              الرقم الضريبي / TRN : {{setting.vat_number}}
                            </div>
                            <div class="mt-2 mb-2" style="border-top:1px dashed #000;border-bottom:1px dashed #000;padding:4px 0;">
                              <strong>فاتورة ضريبية مبسطة</strong><br>
                              <strong>Simplified Tax Invoice</strong>
                            </div>
                          </div>
                          <div style="font-size:10px;">
                            <div v-show="pos_settings.show_reference !== 0" style="display:flex;justify-content:space-between;">
                              <span>Invoice No</span>
                              <span>REF-12345</span>
                              <span>رقم الفاتورة</span>
                            </div>
                            <div v-show="pos_settings.show_date !== 0" style="display:flex;justify-content:space-between;">
                              <span>Date</span>
                              <span>2025-12-10 12:34</span>
                              <span>تاريخ</span>
                            </div>
                            <div v-show="pos_settings.show_seller !== 0" style="display:flex;justify-content:space-between;">
                              <span>Seller</span>
                              <span>John Doe</span>
                              <span>البائع</span>
                            </div>
                            <div v-show="pos_settings.show_customer" style="display:flex;justify-content:space-between;">
                              <span>Customer</span>
                              <span>Jane Smith</span>
                              <span>العميل</span>
                            </div>
                            <div v-show="pos_settings.show_Warehouse" style="display:flex;justify-content:space-between;">
                              <span>Warehouse</span>
                              <span>Main Store</span>
                              <span>المستودع</span>
                            </div>
                          </div>
                          <table style="width:100%;margin-top:8px;font-size:10px;border-top:1px dashed #000;">
                            <thead>
                              <tr>
                                <th style="text-align:left;padding:4px 0;">Product<br>المنتج</th>
                                <th style="text-align:center;padding:4px 0;">Qty<br>كمية</th>
                                <th style="text-align:center;padding:4px 0;">Rate<br>معدل</th>
                                <th style="text-align:right;padding:4px 0;">Amount<br>مجموع</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr>
                                <td>Demo Product A</td>
                                <td style="text-align:center">2</td>
                                <td style="text-align:center">10.00</td>
                                <td style="text-align:right">20.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_product_discount !== 0" style="border-bottom:1px dashed #eee;">
                                <td colspan="4" style="color:#888;font-style:italic;font-size:9px;padding:0 0 2px 4px;">Discount / تخفيض: -2.00</td>
                              </tr>
                              <tr style="border-bottom:1px dashed #eee;">
                                <td>Demo Product B</td>
                                <td style="text-align:center">1</td>
                                <td style="text-align:center">5.00</td>
                                <td style="text-align:right">5.00</td>
                              </tr>
                            </tbody>
                          </table>
                          <table style="width:100%;font-size:10px;border-top:1px dashed #000;margin-top:4px;">
                            <colgroup><col style="width:35%"><col style="width:5%"><col style="width:25%"><col style="width:35%"></colgroup>
                            <tbody>
                              <tr>
                                <td style="text-align:left" class="total">Sub Total</td>
                                <td class="total">:</td>
                                <td style="text-align:center" class="total">25.00</td>
                                <td style="text-align:right" class="total">المجموع الفرعي</td>
                              </tr>
                              <tr v-show="pos_settings.show_tax">
                                <td style="text-align:left" class="total">VAT @ Total</td>
                                <td class="total">:</td>
                                <td style="text-align:center" class="total">1.25</td>
                                <td style="text-align:right" class="total">قيمة الضريبة</td>
                              </tr>
                              <tr v-show="pos_settings.show_discount">
                                <td style="text-align:left" class="total">Discount</td>
                                <td class="total">:</td>
                                <td style="text-align:center" class="total">0.00</td>
                                <td style="text-align:right" class="total">تخفيض</td>
                              </tr>
                              <tr v-show="pos_settings.show_shipping">
                                <td style="text-align:left" class="total">Shipping</td>
                                <td class="total">:</td>
                                <td style="text-align:center" class="total">1.25</td>
                                <td style="text-align:right" class="total">الشحن</td>
                              </tr>
                            </tbody>
                          </table>
                          <table style="width:100%;font-size:10px;font-weight:bold;border-top:1px dashed #000;border-bottom:1px dashed #000;margin-top:4px;padding:4px 0;">
                            <colgroup><col style="width:35%"><col style="width:5%"><col style="width:25%"><col style="width:35%"></colgroup>
                            <tbody>
                              <tr>
                                <td style="text-align:left">Grand Total</td>
                                <td>:</td>
                                <td style="text-align:center">26.25</td>
                                <td style="text-align:right">المبلغ الإجمالي</td>
                              </tr>
                            </tbody>
                          </table>
                          <table style="width:100%;font-size:10px;margin-top:4px;">
                            <colgroup><col style="width:35%"><col style="width:5%"><col style="width:25%"><col style="width:35%"></colgroup>
                            <tbody>
                              <tr v-show="pos_settings.show_paid !== 0">
                                <td style="text-align:left"><strong>Paid Amount</strong></td>
                                <td><strong>:</strong></td>
                                <td style="text-align:center">25.00</td>
                                <td style="text-align:right"><strong>المبلغ المدفوع</strong></td>
                              </tr>
                              <tr v-show="pos_settings.show_due !== 0">
                                <td style="text-align:left"><strong>Balance</strong></td>
                                <td><strong>:</strong></td>
                                <td style="text-align:center">1.25</td>
                                <td style="text-align:right"><strong>الرصيد</strong></td>
                              </tr>
                            </tbody>
                          </table>
                          <table style="font-size:10px;width:100%;margin-top:4px;" v-show="pos_settings.show_payments !== 0">
                            <thead>
                              <tr style="background:#eee;">
                                <th style="text-align:left;">Paid By / طريقة الدفع:</th>
                                <th style="text-align:center;">Amount / المبلغ:</th>
                                <th style="text-align:right;">Change / الباقي:</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr>
                                <td style="text-align:left;">Cash</td>
                                <td style="text-align:center;">25.00</td>
                                <td style="text-align:right;">0.00</td>
                              </tr>
                            </tbody>
                          </table>
                          <p class="mt-2 mb-0 text-center" v-show="pos_settings.show_note" style="white-space:pre-line;">
                            <small><strong>{{ pos_settings.note_customer || 'Thank you for your purchase!' }}</strong></small>
                          </p>
                          <div class="mt-2 text-center" v-show="pos_settings.show_zatca_qr !== 0">
                            <div class="zatca-qr">
                              <div class="zatca-qr-title">ZATCA</div>
                              <div class="demo-qr-box"></div>
                            </div>
                          </div>
                          <div v-if="pos_settings.show_barcode !== 0" class="mt-2 text-center">
                            <BarcodeSvg
                              value="REF-12345"
                              format="CODE128"
                              textmargin="0"
                              fontSize="12"
                              height="40"
                              width="1"
                             />
                          </div>
                        </div>

                        <!-- Layout 5 demo (Minimal) -->
                        <div v-else class="receipt-layout-5">
                          <div class="info text-center mb-3">
                            <div class="invoice_logo mb-2" v-show="pos_settings.show_logo !== 0">
                              <div class="demo-logo-circle small">LOGO</div>
                            </div>
                            <div class="minimal-store-name" v-show="pos_settings.show_store_name !== 0">DEMO STORE</div>
                            <div class="minimal-contact" v-show="pos_settings.show_address || pos_settings.show_phone">
                              <span v-show="pos_settings.show_address">123 Demo Street</span>
                              <span v-show="pos_settings.show_address && pos_settings.show_phone"> &middot; </span>
                              <span v-show="pos_settings.show_phone">+123 456 789</span>
                            </div>
                            <div class="minimal-contact" v-show="pos_settings.show_email">demo@example.com</div>
                          </div>

                          <div class="minimal-divider"></div>

                          <div class="minimal-meta">
                            <div v-show="pos_settings.show_reference !== 0" class="minimal-meta-row">
                              <span>Ref</span><span>REF-12345</span>
                            </div>
                            <div v-show="pos_settings.show_date !== 0" class="minimal-meta-row">
                              <span>Date</span><span>2025-12-10 12:34</span>
                            </div>
                            <div v-show="pos_settings.show_seller !== 0" class="minimal-meta-row">
                              <span>Seller</span><span>John Doe</span>
                            </div>
                            <div v-show="pos_settings.show_customer" class="minimal-meta-row">
                              <span>Customer</span><span>Jane Smith</span>
                            </div>
                            <div v-show="pos_settings.show_Warehouse" class="minimal-meta-row">
                              <span>Warehouse</span><span>Main Store</span>
                            </div>
                          </div>

                          <div class="minimal-divider"></div>

                          <table class="minimal-items">
                            <tbody>
                              <tr>
                                <td>
                                  <div class="minimal-item-name">Demo Product A</div>
                                  <div class="minimal-item-qty">2 &times; 10.00</div>
                                  <div class="minimal-item-discount" v-show="pos_settings.show_product_discount !== 0">Discount &minus;2.00</div>
                                </td>
                                <td class="minimal-item-total">20.00</td>
                              </tr>
                              <tr>
                                <td>
                                  <div class="minimal-item-name">Demo Product B</div>
                                  <div class="minimal-item-qty">1 &times; 5.00</div>
                                </td>
                                <td class="minimal-item-total">5.00</td>
                              </tr>
                            </tbody>
                          </table>

                          <div class="minimal-divider"></div>

                          <table class="minimal-totals">
                            <tbody>
                              <tr v-show="pos_settings.show_tax">
                                <td>Tax</td>
                                <td>1.25</td>
                              </tr>
                              <tr v-show="pos_settings.show_discount">
                                <td>Discount</td>
                                <td>0.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_shipping">
                                <td>Shipping</td>
                                <td>1.25</td>
                              </tr>
                              <tr class="minimal-grand">
                                <td>Total</td>
                                <td>25.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_paid !== 0">
                                <td>Paid</td>
                                <td>20.00</td>
                              </tr>
                              <tr v-show="pos_settings.show_due !== 0">
                                <td>Due</td>
                                <td>5.00</td>
                              </tr>
                            </tbody>
                          </table>

                          <table class="minimal-payments" v-show="pos_settings.show_payments !== 0">
                            <thead>
                              <tr>
                                <th>Pay By</th>
                                <th>Amount</th>
                                <th>Change</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr>
                                <td>Cash</td>
                                <td>20.00</td>
                                <td>0.00</td>
                              </tr>
                            </tbody>
                          </table>

                          <p class="minimal-note" v-show="pos_settings.show_note" style="white-space:pre-line;">
                            {{ pos_settings.note_customer || 'Thank you for your purchase!' }}
                          </p>

                          <div class="mt-2 text-center" v-show="pos_settings.show_zatca_qr !== 0">
                            <div class="zatca-qr">
                              <div class="zatca-qr-title">ZATCA</div>
                              <div class="demo-qr-box"></div>
                            </div>
                          </div>

                          <div v-if="pos_settings.show_barcode !== 0" class="mt-2 text-center">
                            <BarcodeSvg
                              value="REF-12345"
                              format="CODE128"
                              textmargin="0"
                              fontSize="12"
                              height="40"
                              width="1"
                             />
                          </div>
                        </div>
                      </div>
      </a-card>

      <!-- Settings -->
      <a-card :title="$t('Receipt_Settings')">
        <a-form layout="vertical">
          <a-form-item
            :label="$t('Note_to_customer') + ' *'"
            :validate-status="noteTouched && !pos_settings.note_customer ? 'error' : undefined"
            :help="noteTouched && !pos_settings.note_customer ? $t('Field_is_required') : undefined"
          >
            <a-textarea
              v-model:value="pos_settings.note_customer"
              :rows="4"
              :placeholder="$t('Note_to_customer')"
            />
          </a-form-item>

          <a-row :gutter="[16, 8]" style="margin-bottom: 16px">
            <a-col v-for="tg in toggles" :key="tg.field" :xs="12" :md="8">
              <a-switch
                :checked="pos_settings[tg.field] !== 0 && pos_settings[tg.field] !== false && pos_settings[tg.field] !== '0'"
                size="small"
                @change="v => (pos_settings[tg.field] = v ? 1 : 0)"
              />
              <span style="margin-left: 8px">{{ $t(tg.label) }}</span>
              <div v-if="tg.desc" class="toggle-desc">{{ $t(tg.desc) }}</div>
            </a-col>
          </a-row>

          <a-divider />

          <a-row :gutter="16">
            <a-col :xs="24" :md="8">
              <a-form-item :label="$t('Receipt_Paper_Size')">
                <a-select
                  v-model:value="pos_settings.receipt_paper_size"
                  :options="[
                    { value: 58, label: $t('Paper_58mm') },
                    { value: 80, label: $t('Paper_80mm') },
                    { value: 88, label: $t('Paper_88mm') },
                  ]"
                />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="8">
              <a-form-item :label="$t('Logo_Size')">
                <a-select
                  v-model:value="logoSizeType"
                  :options="[
                    { value: 'small', label: $t('Small') + ' (40px)' },
                    { value: 'medium', label: $t('Medium') + ' (60px)' },
                    { value: 'large', label: $t('Large') + ' (80px)' },
                    { value: 'custom', label: $t('Custom') },
                  ]"
                  @change="onLogoSizeTypeChange"
                />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="8" v-if="logoSizeType === 'custom'">
              <a-form-item :label="$t('Custom_Logo_Size') + ' (px)'" :help="$t('Logo_Size_Description')">
                <a-input-number v-model:value="pos_settings.logo_size" :min="20" :max="200" style="width: 100%" />
              </a-form-item>
            </a-col>
          </a-row>

          <a-row :gutter="16">
            <a-col :xs="24" :md="8">
              <a-form-item :label="$t('Receipt_Font_Family')" :help="$t('Receipt_Font_Family_Desc')">
                <a-select
                  v-model:value="pos_settings.receipt_font_family"
                  :options="fontFamilyOptions"
                >
                  <template #option="{ value, label }">
                    <span :style="value ? { fontFamily: value } : {}">{{ label }}</span>
                  </template>
                </a-select>
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="8">
              <a-form-item :label="$t('Receipt_Font_Size')" :help="$t('Receipt_Font_Size_Desc')">
                <a-input-number
                  v-model:value="pos_settings.receipt_font_size"
                  :min="8"
                  :max="24"
                  :placeholder="$t('Font_Default')"
                  style="width: 100%"
                />
              </a-form-item>
            </a-col>
          </a-row>

          <a-button type="primary" size="large" @click="submit">{{ $t('submit') }}</a-button>
        </a-form>
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * POS receipt designer — GET get_pos_Settings → {pos_settings}; PUT
 * pos_settings/{id} with the exact legacy field list (note the capital W in
 * show_Warehouse). Live preview markup + scoped receipt styles are VERBATIM
 * from legacy: "print demo" copies #pos-receipt-demo innerHTML into a popup
 * styled by /css/pos_print.css, so the demo DOM must match real receipts.
 * Legacy quirks kept: note_customer required (only validated field);
 * logo size presets small/medium/large = 40/60/80px, custom free input;
 * toggles are 1/0 ints; ZATCA QR box reads vat_number from
 * get_Settings_data_api (display only).
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PrinterOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import BarcodeSvg from '../../components/BarcodeSvg.vue';
import http from '../../lib/http';
import { receiptFontHeadTags, whenPrintFontsReady } from '../../lib/receiptFont';

const { t } = useI18n();

const isLoading = ref(true);
const logoSizeType = ref('medium');
const noteTouched = ref(false);
const setting = ref({ vat_number: '' });

const pos_settings = ref({
  note_customer: '',
  show_logo: '',
  logo_size: 60,
  show_store_name: '',
  show_reference: '',
  show_date: '',
  show_seller: '',
  show_note: '',
  show_barcode: '',
  show_discount: '',
  show_product_discount: 1,
  show_tax: '',
  show_items_tax: 0,
  show_shipping: '',
  show_phone: '',
  show_email: '',
  show_address: '',
  show_customer: '',
  show_Warehouse: '',
  is_printable: '',
  products_per_page: '',
  receipt_layout: 1,
  receipt_paper_size: 80,
  receipt_font_family: '',
  receipt_font_size: null,
  show_paid: '',
  show_due: '',
  show_payments: '',
  show_zatca_qr: '',
  cash_drawer_auto_open: false,
  cash_drawer_printer_name: '',
});

// Same field/label pairs as legacy's 21 switches. The tax/discount pairs are
// easy to confuse, so those four carry a helper description shown under the label.
const toggles = [
  { field: 'show_logo', label: 'Show_Logo' },
  { field: 'show_store_name', label: 'Show_Store_Name' },
  { field: 'show_reference', label: 'Show_Reference' },
  { field: 'show_date', label: 'Show_Date' },
  { field: 'show_seller', label: 'Show_Seller' },
  { field: 'show_phone', label: 'Show_Phone' },
  { field: 'show_address', label: 'Show_Address' },
  { field: 'show_email', label: 'Show_Email' },
  { field: 'show_customer', label: 'Show_Customer' },
  { field: 'show_Warehouse', label: 'Show_Warehouse' },
  { field: 'show_tax', label: 'Show_Tax', desc: 'Show_Tax_Desc' },
  { field: 'show_items_tax', label: 'Show_Items_Tax', desc: 'Show_Items_Tax_Desc' },
  { field: 'show_discount', label: 'Show_Discount', desc: 'Show_Discount_Desc' },
  { field: 'show_product_discount', label: 'Show_Product_Discount', desc: 'Show_Product_Discount_Desc' },
  { field: 'show_shipping', label: 'Show_Shipping' },
  { field: 'show_barcode', label: 'Show_barcode' },
  { field: 'show_note', label: 'Show_Note_to_customer' },
  { field: 'show_paid', label: 'Show_Paid_Line' },
  { field: 'show_due', label: 'Show_Due_Line' },
  { field: 'show_payments', label: 'Show_Payments_Table' },
  { field: 'show_zatca_qr', label: 'Show_ZATCA_QR' },
];

const currentReceiptLayout = computed(() => {
  const n = Number(pos_settings.value.receipt_layout) || 1;
  return [1, 2, 3, 4, 5].includes(n) ? n : 1;
});

// Curated print-safe font stacks, including every font the legacy app used:
// Inter was the old admin panel's global font (what receipts displayed in
// before the Vue 3 migration), Ubuntu the pos_print.css stack for layouts
// 1-3, and the Segoe UI stacks belong to layouts 4-5. Empty value = keep
// pos_print.css defaults.
const fontFamilyOptions = computed(() => [
  { value: '', label: t('Font_Default') },
  { value: "'Inter', 'Segoe UI', Arial, sans-serif", label: 'Inter' },
  { value: "'Ubuntu', sans-serif", label: 'Ubuntu' },
  { value: "'Segoe UI', Roboto, sans-serif", label: 'Segoe UI' },
  { value: "'Courier New', Courier, monospace", label: 'Courier New' },
  { value: 'Arial, Helvetica, sans-serif', label: 'Arial' },
  { value: 'Helvetica, Arial, sans-serif', label: 'Helvetica' },
  { value: 'Roboto, Arial, sans-serif', label: 'Roboto' },
  { value: 'Tahoma, Geneva, sans-serif', label: 'Tahoma' },
  { value: 'Geneva, Verdana, sans-serif', label: 'Geneva' },
  { value: 'Verdana, Geneva, sans-serif', label: 'Verdana' },
  { value: "'Times New Roman', Times, serif", label: 'Times New Roman' },
  { value: 'Georgia, serif', label: 'Georgia' },
]);

// Inline style for the live demo: inheritance-based so the size hierarchy
// (small print, titles) stays visible while previewing.
const previewFontStyle = computed(() => {
  const style = {};
  const family = String(pos_settings.value.receipt_font_family || '').trim();
  const size = Number(pos_settings.value.receipt_font_size);
  if (family) style.fontFamily = family;
  if (size >= 8 && size <= 24) style.fontSize = `${size}px`;
  return style;
});


function onLogoSizeTypeChange(value) {
  const selected = value || logoSizeType.value;
  if (selected === 'small') pos_settings.value.logo_size = 40;
  else if (selected === 'medium') pos_settings.value.logo_size = 60;
  else if (selected === 'large') pos_settings.value.logo_size = 80;
  if (selected === 'custom' && !pos_settings.value.logo_size) pos_settings.value.logo_size = 60;
}

async function submit() {
  noteTouched.value = true;
  if (!pos_settings.value.note_customer) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  try {
    const s = pos_settings.value;
    await http.put(`pos_settings/${s.id}`, {
      note_customer: s.note_customer,
      show_logo: s.show_logo,
      logo_size: s.logo_size,
      show_store_name: s.show_store_name,
      show_reference: s.show_reference,
      show_date: s.show_date,
      show_seller: s.show_seller,
      show_note: s.show_note,
      show_barcode: s.show_barcode,
      show_discount: s.show_discount,
      show_product_discount: s.show_product_discount,
      show_tax: s.show_tax,
      show_items_tax: s.show_items_tax,
      show_shipping: s.show_shipping,
      show_phone: s.show_phone,
      show_email: s.show_email,
      show_address: s.show_address,
      show_customer: s.show_customer,
      show_Warehouse: s.show_Warehouse,
      is_printable: s.is_printable,
      receipt_paper_size: s.receipt_paper_size,
      show_paid: s.show_paid,
      show_due: s.show_due,
      show_payments: s.show_payments,
      show_zatca_qr: s.show_zatca_qr,
      receipt_layout: s.receipt_layout,
      receipt_font_family: s.receipt_font_family || '',
      receipt_font_size: s.receipt_font_size,
    });
    message.success(t('Successfully_Updated'));
    await loadSettings();
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

function printPosDemo() {
  try {
    const el = document.getElementById('pos-receipt-demo');
    if (!el) return;
    const w = window.open('', '', 'height=600,width=400');
    w.document.write('<html><head>');
    w.document.write('<link rel="stylesheet" href="/css/pos_print.css">');
    w.document.write(receiptFontHeadTags(pos_settings.value, 'body'));
    w.document.write('</head><body>');
    w.document.write(el.innerHTML);
    w.document.write('</body></html>');
    w.document.close();
    whenPrintFontsReady(w, () => { try { w.print(); } catch (e) {} });
  } catch (e) { /* preview print errors are non-fatal, like legacy */ }
}

async function loadSettings() {
  try {
    const data = await http.get('get_pos_Settings');
    pos_settings.value = { ...pos_settings.value, ...(data?.pos_settings || {}) };
    if (!pos_settings.value.logo_size) pos_settings.value.logo_size = 60;
    // Null from the DB must map to the '' "Default" option of the font select.
    if (pos_settings.value.receipt_font_family == null) pos_settings.value.receipt_font_family = '';
    const size = Number(pos_settings.value.logo_size);
    logoSizeType.value = size === 40 ? 'small' : size === 60 ? 'medium' : size === 80 ? 'large' : 'custom';
  } catch (e) { /* keep defaults */ }
  try {
    const data = await http.get('get_Settings_data_api');
    setting.value.vat_number = data?.settings?.vat_number || '';
  } catch (e) { /* display-only */ }
  isLoading.value = false;
}

onMounted(loadSettings);
</script>

<style scoped>
.pos-receipt-demo {
  /* Approximate 88mm receipt width at 96dpi: ~332px */
  width: 330px;
  max-width: 100%;
  margin: 0 auto;
  background: #ffffff;
  padding: 10px;
  border: 1px dashed #dee2e6;
  font-size: 11px;
}

.pos-receipt-demo .info {
  text-align: center;
}

.pos-receipt-demo .table_data {
  width: 100%;
}

/* Demo logo styles */
.demo-logo-circle {
  width: 60px;
  height: 60px;
  border-radius: 50%;
  background: #e9ecef;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  font-weight: bold;
  color: #6c757d;
}

.demo-logo-circle.small {
  width: 40px;
  height: 40px;
  font-size: 8px;
}

.demo-logo-rect {
  width: 60px;
  height: 40px;
  background: #e9ecef;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 8px;
  font-weight: bold;
  color: #6c757d;
  border-radius: 4px;
}

.demo-qr-box {
  width: 80px;
  height: 80px;
  background: #f8f9fa;
  border: 1px solid #dee2e6;
  margin: 0 auto;
}

/* The demo markup is verbatim legacy Bootstrap HTML, but this SPA ships no
   Bootstrap utilities — define the one the QR/barcode blocks rely on so
   they center like the printed receipt (text-align inherits into the
   BarcodeSvg wrapper; its svg is inline, so it centers too). */
.pos-receipt-demo .text-center {
  text-align: center;
}

/* Helper text under the tax/discount toggles. Indent past the small switch
   (28px) + its 8px gap so it aligns with the label above. */
.toggle-desc {
  margin: 2px 0 0 36px;
  font-size: 12px;
  color: #8c8c8c;
  line-height: 1.4;
}

.zatca-qr {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 100%;
}

.zatca-qr-title {
  font-weight: 700;
  font-size: 10px;
  margin-bottom: 4px;
  letter-spacing: 1px;
  text-transform: uppercase;
}

/* Layout 3 specific styles */
.receipt-layout-3 .info {
  text-align: left;
}

.receipt-layout-3 .info .d-flex {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}

/* Layout 5 specific styles (Minimal) */
.receipt-layout-5 {
  width: 240px;
  max-width: 100%;
  margin: 0 auto;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  font-size: 11px;
  line-height: 1.4;
  color: #111;
  letter-spacing: 0.2px;
}

.receipt-layout-5 .minimal-store-name {
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  margin-top: 2px;
}

.receipt-layout-5 .minimal-contact {
  font-size: 10px;
  color: #555;
  margin-top: 2px;
}

.receipt-layout-5 .minimal-divider {
  border-top: 1px solid #111;
  margin: 6px 0;
}

.receipt-layout-5 .minimal-meta {
  font-size: 10px;
}

.receipt-layout-5 .minimal-meta-row {
  display: flex;
  justify-content: space-between;
  padding: 1px 0;
}

.receipt-layout-5 .minimal-meta-row span:first-child {
  color: #666;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  font-size: 9px;
}

.receipt-layout-5 .minimal-items {
  width: 100%;
  border-collapse: collapse;
  font-size: 10px;
}

.receipt-layout-5 .minimal-items td {
  padding: 3px 0;
  vertical-align: top;
}

.receipt-layout-5 .minimal-item-name {
  font-weight: 500;
}

.receipt-layout-5 .minimal-item-qty {
  color: #777;
  font-size: 9px;
  margin-top: 1px;
}

.receipt-layout-5 .minimal-item-discount {
  color: #999;
  font-size: 9px;
  font-style: italic;
  margin-top: 1px;
  letter-spacing: 0.3px;
}

.receipt-layout-5 .minimal-item-total {
  text-align: right;
  white-space: nowrap;
}

.receipt-layout-5 .minimal-totals {
  width: 100%;
  border-collapse: collapse;
  font-size: 10px;
}

.receipt-layout-5 .minimal-totals td {
  padding: 2px 0;
}

.receipt-layout-5 .minimal-totals td:last-child {
  text-align: right;
}

.receipt-layout-5 .minimal-totals td:first-child {
  color: #666;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  font-size: 9px;
}

.receipt-layout-5 .minimal-grand td {
  font-size: 12px !important;
  font-weight: 700;
  color: #111 !important;
  letter-spacing: 0.5px !important;
  padding-top: 6px;
  border-top: 1px solid #111;
  text-transform: none !important;
}

.receipt-layout-5 .minimal-payments {
  width: 100%;
  border-collapse: collapse;
  font-size: 10px;
  margin-top: 6px;
}

.receipt-layout-5 .minimal-payments th {
  font-weight: 500;
  color: #666;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  font-size: 9px;
  padding: 3px 0;
  border-top: 1px solid #eee;
  border-bottom: 1px solid #eee;
}

.receipt-layout-5 .minimal-payments th:nth-child(2),
.receipt-layout-5 .minimal-payments td:nth-child(2) {
  text-align: center;
}

.receipt-layout-5 .minimal-payments th:nth-child(3),
.receipt-layout-5 .minimal-payments td:nth-child(3) {
  text-align: right;
}

.receipt-layout-5 .minimal-payments td {
  padding: 2px 0;
}

.receipt-layout-5 .minimal-note {
  text-align: center;
  font-size: 10px;
  color: #555;
  margin: 8px 0 0;
  font-style: italic;
}

/* Responsive styles for mobile */
@media (max-width: 768px) {
  /* Make layout radio buttons responsive */
  .form-group {
    width: 100%;
  }

  .btn-group-toggle.btn-group {
    display: flex;
    flex-wrap: wrap;
    width: 100%;
  }

  .btn-group-toggle.btn-group .btn {
    flex: 1;
    min-width: 0;
    font-size: 0.875rem;
    padding: 0.25rem 0.5rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .pos-receipt-demo {
    width: 100%;
    padding: 12px;
    font-size: 10px;
  }

  .pos-receipt-demo .table_data {
    font-size: 10px !important;
  }

  .demo-logo-circle {
    width: 50px;
    height: 50px;
    font-size: 9px;
  }

  .demo-logo-circle.small {
    width: 35px;
    height: 35px;
    font-size: 7px;
  }

  .demo-logo-rect {
    width: 50px;
    height: 35px;
    font-size: 7px;
  }

  .demo-qr-box {
    width: 70px;
    height: 70px;
  }

  .zatca-qr-title {
    font-size: 9px;
  }

  /* Make tables horizontally scrollable on mobile if needed */
  .pos-receipt-demo {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }

  .pos-receipt-demo .table_data {
    min-width: 100%;
  }

  .pos-receipt-demo .table_data td,
  .pos-receipt-demo .table_data th {
    white-space: nowrap;
    padding: 2px 4px;
  }

  /* Allow text wrapping for product names */
  .pos-receipt-demo .table_data td:first-child {
    white-space: normal;
    word-wrap: break-word;
  }

  /* Adjust layout 3 header for mobile */
  .receipt-layout-3 .info .d-flex {
    flex-direction: column;
    gap: 8px;
  }

  .receipt-layout-3 .demo-logo-rect {
    align-self: flex-end;
  }
}

@media (max-width: 480px) {
  /* Stack layout buttons vertically on small screens */
  .btn-group-toggle.btn-group {
    flex-direction: column;
  }

  .btn-group-toggle.btn-group .btn {
    width: 100%;
    margin-bottom: 4px;
    border-radius: 0.25rem !important;
  }

  .btn-group-toggle.btn-group .btn:first-child {
    border-top-left-radius: 0.25rem !important;
    border-top-right-radius: 0.25rem !important;
    border-bottom-left-radius: 0.25rem !important;
    border-bottom-right-radius: 0.25rem !important;
  }

  .btn-group-toggle.btn-group .btn:last-child {
    border-bottom-left-radius: 0.25rem !important;
    border-bottom-right-radius: 0.25rem !important;
    margin-bottom: 0;
  }

  .btn-group-toggle.btn-group .btn {
    font-size: 0.8rem;
    padding: 0.375rem 0.5rem;
    white-space: normal;
    word-wrap: break-word;
  }

  .pos-receipt-demo {
    padding: 8px;
    font-size: 9px;
  }

  .pos-receipt-demo .table_data {
    font-size: 9px !important;
  }

  .demo-logo-circle {
    width: 40px;
    height: 40px;
    font-size: 8px;
  }

  .demo-logo-circle.small {
    width: 30px;
    height: 30px;
    font-size: 6px;
  }

  .demo-logo-rect {
    width: 40px;
    height: 30px;
    font-size: 6px;
  }

  .demo-qr-box {
    width: 60px;
    height: 60px;
  }

  .zatca-qr-title {
    font-size: 8px;
  }

  /* Ensure text doesn't overflow */
  .pos-receipt-demo small {
    word-wrap: break-word;
    overflow-wrap: break-word;
  }

  /* Barcode container already handles sizing via max-width: 100% */
}

/* Ensure receipt preview card is responsive */
@media (max-width: 768px) {
  .pos-receipt-demo {
    margin: 0;
  }
}

/* Make sure tables don't break layout on very small screens */
@media (max-width: 360px) {
  .pos-receipt-demo {
    font-size: 8px;
    padding: 6px;
  }

  .pos-receipt-demo .table_data {
    font-size: 8px !important;
  }

  .pos-receipt-demo td,
  .pos-receipt-demo th {
    padding: 2px 4px;
  }
}
</style>
