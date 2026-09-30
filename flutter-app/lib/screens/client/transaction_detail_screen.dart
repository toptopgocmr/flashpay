import 'package:flutter/material.dart';
import '../shared/support_screen.dart';
import 'package:intl/intl.dart';
import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../services/receipt.dart';
import '../../services/transaction_service.dart';
import '../../widgets/transaction_tile.dart' show fpStatusLabel;
import '../../l10n/l10n.dart';

class TransactionDetailScreen extends StatefulWidget {
  final int transactionId;
  const TransactionDetailScreen({super.key, required this.transactionId});

  @override
  State<TransactionDetailScreen> createState() => _TransactionDetailScreenState();
}

class _TransactionDetailScreenState extends State<TransactionDetailScreen> {
  final _txService = TransactionService();
  Map<String, dynamic>? _tx;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final data = await _txService.detail(widget.transactionId);
      if (mounted) setState(() => _tx = data);
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    }
  }

  Color _statusColor(String? status) => switch (status) {
        'successful' => FpColors.success,
        'failed' || 'reversed' => FpColors.danger,
        _ => Colors.orange,
      };

  IconData _statusIcon(String? status) => switch (status) {
        'successful' => Icons.check_circle,
        'failed' => Icons.cancel,
        'reversed' => Icons.undo_rounded,
        _ => Icons.schedule_rounded,
      };

  String _money(dynamic v, dynamic cur) => '${NumberFormat('#,###', 'fr_FR').format((v as num?) ?? 0).replaceAll(',', ' ').replaceAll(' ', ' ')} ${cur ?? 'XAF'}';

  @override
  Widget build(BuildContext context) {
    final t = _tx;
    final meta = (t?['meta'] is Map) ? t!['meta'] as Map : {};
    return Scaffold(
      appBar: AppBar(
        title: Text(tr('Détail de la transaction')),
        actions: [
          // §13.2 — contester une opération (paiement non reconnu, montant erroné, cash-out non reçu…)
          IconButton(
            tooltip: tr('Contester'),
            onPressed: () => showDisputeSheet(context, widget.transactionId),
            icon: Icon(Icons.report_gmailerrorred, color: FpColors.danger),
          ),
        ],
      ),
      body: t == null
          ? Center(child: _error != null ? Padding(padding: EdgeInsets.all(24), child: Text(_error!, textAlign: TextAlign.center)) : CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: EdgeInsets.all(20),
                children: [
                  Center(
                    child: Column(
                      children: [
                        Icon(_statusIcon(t['status']), color: _statusColor(t['status']), size: 56),
                        SizedBox(height: 8),
                        Text(_money(t['amount'], t['currency']), style: TextStyle(fontSize: 28, fontWeight: FontWeight.bold)),
                        SizedBox(height: 4),
                        Container(
                          padding: EdgeInsets.symmetric(horizontal: 12, vertical: 3),
                          decoration: BoxDecoration(color: _statusColor(t['status']).withOpacity(.1), borderRadius: BorderRadius.circular(99)),
                          child: Text('${t['status_label'] ?? fpStatusLabel(t['status'])}',
                              style: TextStyle(color: _statusColor(t['status']), fontWeight: FontWeight.w700)),
                        ),
                      ],
                    ),
                  ),
                  if (t['status'] == 'failed' && (t['failure_reason'] ?? '').toString().isNotEmpty) ...[
                    SizedBox(height: 14),
                    Container(
                      padding: EdgeInsets.all(12),
                      decoration: BoxDecoration(color: Color(0xFFFEF2F2), borderRadius: BorderRadius.circular(12)),
                      child: Text('Motif : ${t['failure_reason']}', style: TextStyle(color: Color(0xFF991B1B), fontSize: 13)),
                    ),
                  ],
                  SizedBox(height: 20),
                  Card(
                    child: Padding(
                      padding: EdgeInsets.all(16),
                      child: Column(
                        children: [
                          _row('Référence', '${t['reference']}'),
                          _row('Opération', '${t['type_label'] ?? t['type']}'),
                          if ((t['source_account'] ?? '').toString().isNotEmpty) _row('Payé depuis', '${t['source_account']}'),
                          if ((meta['beneficiary_name'] ?? meta['merchant_name']) != null) _row('Bénéficiaire', '${meta['merchant_name'] ?? meta['beneficiary_name']}'),
                          if ((t['destination_account'] ?? '').toString().isNotEmpty) _row('Reçu sur', '${t['destination_account']}'),
                          _row('Frais', _money(t['fee'], t['currency'])),
                          _row('Total débité', _money(((t['amount'] as num?) ?? 0) + ((t['fee'] as num?) ?? 0), t['currency'])),
                          _row('Date', DateFormat('dd/MM/yyyy à HH:mm').format(DateTime.parse('${t['created_at']}').toLocal())),
                          if (meta['note'] != null) _row('Motif', '${meta['note']}'),
                        ],
                      ),
                    ),
                  ),
                  SizedBox(height: 16),
                  FpReceiptButtons(transactionId: widget.transactionId, reference: '${t['reference']}'),
                  SizedBox(height: 8),
                  TextButton.icon(
                    onPressed: () => showDisputeSheet(context, widget.transactionId),
                    icon: Icon(Icons.report_gmailerrorred, color: FpColors.danger),
                    label: Text(tr('Un problème avec cette opération ? Contester'), style: TextStyle(color: FpColors.danger)),
                  ),
                ],
              ),
            ),
    );
  }

  Widget _row(String label, String value) => Padding(
        padding: EdgeInsets.symmetric(vertical: 6),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(width: 110, child: Text(tr(label), style: TextStyle(color: Colors.grey))),
            Expanded(child: Text(value, textAlign: TextAlign.right, style: TextStyle(fontWeight: FontWeight.w600))),
          ],
        ),
      );
}
