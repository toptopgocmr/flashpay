import 'dart:convert';
import 'dart:typed_data';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:share_plus/share_plus.dart';
import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../services/merchant_service.dart';
import '../../services/payment_service.dart';
import '../../services/pro_service.dart';
import '../../widgets/fp_ui.dart';
import '../../l10n/l10n.dart';

/// Rapports marchand (§3.2.3, §3.2.4, §4.6.5) : consolidation par point de
/// vente, caissier et canal ; export du relevé ; remboursement (§13.2).
class MerchantReportsScreen extends StatefulWidget {
  const MerchantReportsScreen({super.key});

  @override
  State<MerchantReportsScreen> createState() => _MerchantReportsScreenState();
}

class _MerchantReportsScreenState extends State<MerchantReportsScreen> {
  final _service = MerchantToolsService();
  Map<String, dynamic>? _r;
  List<Map<String, dynamic>> _recent = [];
  int _days = 7;

  @override
  void initState() {
    super.initState();
    _load();
  }

  String _iso(DateTime d) => '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<void> _load() async {
    try {
      final from = _iso(DateTime.now().subtract(Duration(days: _days - 1)));
      final r = await Future.wait([_service.reports(from: from), MerchantService().collections()]);
      if (!mounted) return;
      setState(() {
        _r = r[0];
        _recent = (((r[1])['data'] ?? []) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      });
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  Future<void> _export() async {
    try {
      final from = _iso(DateTime.now().subtract(Duration(days: _days - 1)));
      final res = await ApiClient().dio.get<List<int>>('/merchant/statement', queryParameters: {'from': from}, options: Options(responseType: ResponseType.bytes));
      final bytes = Uint8List.fromList(res.data ?? utf8.encode(''));
      await Share.shareXFiles([XFile.fromData(bytes, mimeType: 'text/csv', name: 'releve-flashpay-$from.csv')], subject: 'Relevé FlashPay');
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  Future<void> _refund(Map<String, dynamic> t) async {
    final amount = TextEditingController(text: '${t['amount']}');
    final reason = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(tr('Rembourser le client')),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          FpAmountField(controller: amount, label: tr('Montant (total ou partiel)')),
          SizedBox(height: 8),
          TextField(controller: reason, decoration: InputDecoration(labelText: tr('Motif'))),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(tr('Rembourser'))),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await _service.refund(transactionId: fpInt(t['id']), amount: int.tryParse(amount.text.replaceAll(' ', '')), reason: reason.text.trim());
      if (mounted) fpSnack(context, 'Remboursement effectué : le client est crédité immédiatement.');
      _load();
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  Widget _group(String title, String key, String labelKey) {
    final rows = ((_r?[key] ?? []) as List).cast<Map>();
    if (rows.isEmpty) return SizedBox.shrink();
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      FpSectionTitle(title),
      Card(child: Column(children: rows.map((x) => ListTile(
            dense: true,
            title: Text('${x[labelKey]}'),
            subtitle: Text('${x['count']} encaissement(s)'),
            trailing: Text(fpMoney(fpInt(x['net'])), style: TextStyle(fontWeight: FontWeight.w700)),
          )).toList())),
    ]);
  }

  @override
  Widget build(BuildContext context) {
    final total = (_r?['total'] ?? {}) as Map;
    return Scaffold(
      appBar: AppBar(title: Text(tr('Rapports & relevé')), actions: [IconButton(tooltip: tr('Exporter (CSV)'), icon: Icon(Icons.download), onPressed: _export)]),
      body: _r == null
          ? Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(padding: EdgeInsets.all(16), children: [
                SegmentedButton<int>(
                  segments: [ButtonSegment(value: 1, label: Text(tr('Jour'))), ButtonSegment(value: 7, label: Text(tr('7 jours'))), ButtonSegment(value: 30, label: Text(tr('30 jours')))],
                  selected: {_days},
                  onSelectionChanged: (s) { setState(() => _days = s.first); _load(); },
                ),
                SizedBox(height: 12),
                Card(child: Padding(
                  padding: EdgeInsets.all(16),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(tr('Net encaissé'), style: TextStyle(color: Colors.black54)),
                    Text(fpMoney(fpInt(total['net'])), style: TextStyle(fontSize: 28, fontWeight: FontWeight.w900, color: FpColors.navy)),
                    Text('${total['count'] ?? 0} encaissement(s) · brut ${fpMoney(fpInt(total['gross']))} · commissions ${fpMoney(fpInt(total['fees']))}', style: TextStyle(fontSize: 12)),
                  ]),
                )),
                _group('Par point de vente', 'by_outlet', 'name'),
                _group('Par caissier', 'by_cashier', 'name'),
                _group('Par canal (QR, NFC, manuel, e-commerce…)', 'by_channel', 'channel'),
                FpSectionTitle('Derniers encaissements'),
                ..._recent.take(20).map((t) => Card(child: ListTile(
                      title: Text('${t['meta']?['payer_name'] ?? 'Client'} · ${fpMoney(fpInt(t['amount']), '${t['currency'] ?? 'XAF'}')}'),
                      subtitle: Text('${t['reference']} · ${fpDate(t['created_at'])}'),
                      trailing: t['status'] == 'successful' && '${t['type']}'.contains('payment')
                          ? TextButton(onPressed: () => _refund(t), child: Text(tr('Rembourser')))
                          : FpStatusChip('${t['status']}', tone: t['status'] == 'successful' ? 'ok' : 'warn'),
                    ))),
              ]),
            ),
    );
  }
}
