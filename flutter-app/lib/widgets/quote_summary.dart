import 'package:flutter/material.dart';
import '../config/theme.dart';
import '../models/quote.dart';
import '../services/payment_service.dart';
import '../l10n/l10n.dart';

/// Récapitulatif transparent d'un devis : frais, change, montant reçu.
class FpQuoteSummary extends StatelessWidget {
  final FpQuote quote;
  final String receiveLabel;

  const FpQuoteSummary({super.key, required this.quote, this.receiveLabel = 'Le bénéficiaire reçoit'});

  @override
  Widget build(BuildContext context) {
    final q = quote;
    return Card(
      child: Padding(
        padding: EdgeInsets.all(16),
        child: Column(children: [
          _row('Montant', fpMoney(q.amount, q.currency)),
          _row('Frais FlashPay', q.fee == 0 ? 'Gratuit' : fpMoney(q.fee, q.currency), valueColor: q.fee == 0 ? FpColors.success : null),
          if (q.merchantFee > 0) _row('Commission marchand', fpMoney(q.merchantFee, q.receiveCurrency), muted: true),
          if (q.converted) _row('Taux de change', '1 ${q.currency} = ${q.fxRate.toStringAsFixed(q.fxRate < 1 ? 5 : 3)} ${q.receiveCurrency}', muted: true),
          if (q.destination.type == 'mobile' && q.destination.verifiedName != null)
            _verified('Titulaire du compte bénéficiaire', q.destination.verifiedName!),
          if (q.source.type == 'mobile' && q.source.verifiedName != null)
            _verified('Compte débité', q.source.verifiedName!),
          Divider(height: 20),
          _row('Total débité', fpMoney(q.total, q.currency), bold: true),
          _row(receiveLabel, fpMoney(q.receiveAmount, q.receiveCurrency), bold: true, valueColor: FpColors.navy),
          SizedBox(height: 8),
          Row(children: [
            _pill(q.scopeLabel),
            SizedBox(width: 6),
            if (q.isAsync) _pill('Via opérateur mobile'),
          ]),
          if (!q.available) ...[
            SizedBox(height: 10),
            for (final p in q.problems)
              Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Icon(Icons.error_outline, color: FpColors.danger, size: 18),
                SizedBox(width: 6),
                Expanded(child: Text(p, style: TextStyle(color: FpColors.danger, fontSize: 13))),
              ]),
          ],
        ]),
      ),
    );
  }

  Widget _row(String label, String value, {bool bold = false, bool muted = false, Color? valueColor}) => Padding(
        padding: EdgeInsets.symmetric(vertical: 4),
        child: Row(children: [
          Text(tr(label), style: TextStyle(color: muted ? Colors.grey : Colors.black54, fontSize: 14)),
          Spacer(),
          Text(value, style: TextStyle(fontWeight: bold ? FontWeight.w800 : FontWeight.w600, fontSize: bold ? 16 : 14, color: valueColor)),
        ]),
      );

  Widget _verified(String label, String name) => Padding(
        padding: EdgeInsets.symmetric(vertical: 4),
        child: Row(children: [
          Text(tr(label), style: TextStyle(color: Colors.black54, fontSize: 14)),
          Spacer(),
          Icon(Icons.verified, size: 16, color: FpColors.success),
          SizedBox(width: 4),
          Flexible(child: Text(name, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: FontWeight.w700, fontSize: 14))),
        ]),
      );

  Widget _pill(String t) => Container(
        padding: EdgeInsets.symmetric(horizontal: 10, vertical: 3),
        decoration: BoxDecoration(color: FpColors.background, borderRadius: BorderRadius.circular(20)),
        child: Text(t, style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600)),
      );
}
