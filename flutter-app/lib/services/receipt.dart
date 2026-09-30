import 'package:flutter/material.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';
import 'api_client.dart';

/// Reçu de transaction : le serveur fournit un lien signé (valable 7 jours)
/// vers une page imprimable / enregistrable en PDF.
class FpReceipt {
  static Future<String> link(int transactionId) async {
    final r = await ApiClient().dio.get('/transactions/$transactionId/receipt');
    return '${(r.data as Map)['url']}';
  }

  /// Ouvre le reçu dans le navigateur du téléphone.
  static Future<void> open(BuildContext context, int transactionId) async {
    try {
      final url = await link(transactionId);
      final ok = await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
      if (!ok && context.mounted) _snack(context, "Impossible d'ouvrir le reçu.");
    } catch (e) {
      if (context.mounted) _snack(context, apiErrorMessage(e));
    }
  }

  /// Partage le lien du reçu (WhatsApp, SMS, e-mail…).
  static Future<void> share(BuildContext context, int transactionId, {String? reference}) async {
    try {
      final url = await link(transactionId);
      await Share.share('Reçu FlashPay${reference != null ? ' $reference' : ''} :\n$url', subject: 'Reçu FlashPay');
    } catch (e) {
      if (context.mounted) _snack(context, apiErrorMessage(e));
    }
  }

  static void _snack(BuildContext context, String text) =>
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));
}

/// Deux boutons « Voir le reçu » / « Partager ».
class FpReceiptButtons extends StatelessWidget {
  final int transactionId;
  final String? reference;
  const FpReceiptButtons({super.key, required this.transactionId, this.reference});

  @override
  Widget build(BuildContext context) => Row(children: [
        Expanded(
          child: OutlinedButton.icon(
            onPressed: () => FpReceipt.open(context, transactionId),
            icon: const Icon(Icons.receipt_long_rounded),
            label: const Text('Voir le reçu'),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: OutlinedButton.icon(
            onPressed: () => FpReceipt.share(context, transactionId, reference: reference),
            icon: const Icon(Icons.share_rounded),
            label: const Text('Partager'),
          ),
        ),
      ]);
}
