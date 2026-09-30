import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../config/theme.dart';
import '../models/transaction.dart';
import '../l10n/l10n.dart';

/// Ligne de transaction réutilisée dans l'accueil, l'historique et les
/// encaissements marchand. [isCredit] contrôle le signe (+/-) affiché,
/// [onTap] est optionnel (ex: ouvrir le détail depuis l'accueil).
class TransactionTile extends StatelessWidget {
  final FpTransaction transaction;
  final bool isCredit;
  final VoidCallback? onTap;

  const TransactionTile({super.key, required this.transaction, this.isCredit = false, this.onTap});

  IconData get _icon {
    switch (transaction.type) {
      case 'p2p':
        return Icons.swap_horiz_rounded;
      case 'merchant_payment':
      case 'qr_payment':
      case 'nfc_payment':
      case 'manual_payment':
        return Icons.storefront_rounded;
      case 'cash_in':
      case 'cash_out':
        return Icons.storefront_outlined;
      case 'withdrawal':
        return Icons.account_balance_rounded;
      default:
        return Icons.receipt_long_rounded;
    }
  }

  Color get _statusColor {
    switch (transaction.status) {
      case 'successful':
        return FlashPayColors.success;
      case 'failed':
      case 'reversed':
        return FlashPayColors.danger;
      default:
        return Colors.orange;
    }
  }

  String get _typeLabel {
    const labels = {
      'p2p': "Envoi d'argent",
      'transfer': "Envoi d'argent",
      'merchant_payment': 'Paiement marchand',
      'qr_payment': 'Paiement QR',
      'nfc_payment': 'Paiement NFC',
      'manual_payment': 'Paiement manuel',
      'cash_in': 'Recharge du wallet',
      'deposit': 'Recharge du wallet',
      'cash_out': 'Retrait chez un agent',
      'cash_pickup': 'Retrait avec code',
      'withdrawal': 'Retrait',
      'collection': 'Collecte',
      'gift': 'Cadeau envoyé',
      'gift_claim': 'Cadeau reçu',
      'gift_refund': 'Cadeau recrédité',
      'refund': 'Remboursement',
      'split_payment': 'Part de note partagée',
      'bank_transfer': 'Virement bancaire',
    };
    return labels[transaction.type] ?? transaction.reference;
  }

  @override
  Widget build(BuildContext context) {
    final dateFmt = DateFormat('dd MMM, HH:mm', 'fr_FR');
    final formattedAmount = NumberFormat('#,###', 'fr_FR').format(transaction.amount).replaceAll(',', ' ');
    final sign = isCredit ? '+' : '';

    return ListTile(
      onTap: onTap,
      leading: CircleAvatar(
        backgroundColor: FlashPayColors.background,
        child: Icon(_icon, color: FlashPayColors.navy, size: 20),
      ),
      title: Text(tr(_typeLabel), style: TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
      subtitle: Text(dateFmt.format(transaction.createdAt), style: TextStyle(fontSize: 12)),
      trailing: Column(
        crossAxisAlignment: CrossAxisAlignment.end,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(
            '$sign$formattedAmount ${transaction.currency}',
            style: TextStyle(fontWeight: FontWeight.bold, color: isCredit ? FlashPayColors.success : Colors.black87),
          ),
          SizedBox(height: 2),
          Text(fpStatusLabel(transaction.status), style: TextStyle(fontSize: 11, color: _statusColor, fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }
}

/// Statut d'une opération en français (réutilisé dans tout l'app).
String fpStatusLabel(String? status) => tr(switch (status) {
      'successful' => 'Réussie',
      'failed' => 'Échouée',
      'reversed' => 'Remboursée',
      'processing' || 'pending' => 'En cours',
      _ => status ?? '',
    });
