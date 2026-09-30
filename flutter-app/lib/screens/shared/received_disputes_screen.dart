import 'package:flutter/material.dart';
import '../../config/theme.dart';
import '../../l10n/l10n.dart';
import '../../services/api_client.dart';
import '../../services/payment_service.dart';
import '../../widgets/fp_ui.dart';

/// Contestations reçues : un client (ou un autre utilisateur) conteste une
/// opération dont vous êtes l'autre partie. Vous pouvez le rembourser depuis
/// votre wallet ou répondre ; FlashPay arbitre sinon.
class ReceivedDisputesScreen extends StatefulWidget {
  const ReceivedDisputesScreen({super.key});

  @override
  State<ReceivedDisputesScreen> createState() => _ReceivedDisputesScreenState();
}

class _ReceivedDisputesScreenState extends State<ReceivedDisputesScreen> {
  final _dio = ApiClient().dio;
  List<Map<String, dynamic>>? _rows;
  int? _busy;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await _dio.get('/disputes/received');
      if (mounted) setState(() => _rows = ((r.data ?? []) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList());
    } catch (e) {
      if (mounted) {
        setState(() => _rows = []);
        fpSnack(context, apiErrorMessage(e), error: true);
      }
    }
  }

  Future<void> _refund(Map<String, dynamic> d) async {
    final tx = Map<String, dynamic>.from((d['transaction'] ?? {}) as Map);
    final amount = TextEditingController(text: '${fpInt(tx['amount'])}');
    final message = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(tr('Rembourser le client')),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          Text('${tr('Le montant est débité de votre wallet et crédité à')} ${d['user']?['full_name'] ?? ''}.', style: const TextStyle(fontSize: 13, color: Colors.black54)),
          const SizedBox(height: 10),
          TextField(controller: amount, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Montant'), suffixText: '${tx['currency'] ?? 'XAF'}')),
          const SizedBox(height: 8),
          TextField(controller: message, decoration: InputDecoration(labelText: tr('Message au client (facultatif)'))),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(tr('Rembourser'))),
        ],
      ),
    );
    if (ok != true) return;
    await _act(d, () => _dio.post('/disputes/${d['id']}/refund', data: {
          'amount': int.tryParse(amount.text.replaceAll(RegExp(r'\D'), '')),
          if (message.text.trim().isNotEmpty) 'message': message.text.trim(),
        }), tr('Client remboursé.'));
  }

  Future<void> _respond(Map<String, dynamic> d) async {
    final message = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(tr('Répondre à la contestation')),
        content: TextField(controller: message, maxLines: 4, decoration: InputDecoration(hintText: tr('Expliquez votre position : FlashPay arbitrera.'))),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, message.text.trim().isNotEmpty), child: Text(tr('Envoyer'))),
        ],
      ),
    );
    if (ok != true) return;
    await _act(d, () => _dio.post('/disputes/${d['id']}/respond', data: {'message': message.text.trim()}), tr('Réponse envoyée à FlashPay.'));
  }

  Future<void> _act(Map<String, dynamic> d, Future<dynamic> Function() call, String ok) async {
    setState(() => _busy = fpInt(d['id']));
    try {
      await call();
      if (mounted) fpSnack(context, ok);
      await _load();
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = null);
    }
  }

  static String _status(String s) => switch (s) {
        'open' => 'Ouverte',
        'investigating' => 'En arbitrage',
        'resolved_refunded' => 'Remboursée',
        'resolved_rejected' => 'Rejetée',
        _ => s,
      };

  @override
  Widget build(BuildContext context) {
    final rows = _rows;
    return Scaffold(
      appBar: AppBar(title: Text(tr('Contestations reçues'))),
      body: rows == null
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(padding: const EdgeInsets.all(16), children: [
                if (rows.isEmpty) FpEmpty(tr('Aucune contestation sur vos opérations.'), icon: Icons.verified_outlined),
                ...rows.map((d) {
                  final tx = Map<String, dynamic>.from((d['transaction'] ?? {}) as Map);
                  final status = '${d['status']}';
                  final open = status == 'open' || status == 'investigating';
                  final busy = _busy == fpInt(d['id']);
                  return Card(
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(16, 14, 16, 10),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          Expanded(child: Text('${d['reason_label'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.w700))),
                          FpStatusChip(_status(status), tone: open ? 'warn' : (status == 'resolved_refunded' ? 'ok' : 'info')),
                        ]),
                        const SizedBox(height: 4),
                        Text('${d['user']?['full_name'] ?? ''} · ${d['reference']}', style: const TextStyle(color: FpColors.muted, fontSize: 13)),
                        Text('${tx['reference'] ?? ''} · ${fpMoney(fpInt(tx['amount']), '${tx['currency'] ?? 'XAF'}')} · ${fpDate(tx['created_at'])}',
                            style: const TextStyle(color: FpColors.muted, fontSize: 13)),
                        if ((d['description'] ?? '').toString().isNotEmpty) ...[
                          const SizedBox(height: 6),
                          Text('« ${d['description']} »'),
                        ],
                        if ((d['counterparty_response'] ?? '').toString().isNotEmpty) ...[
                          const SizedBox(height: 6),
                          Text('${tr('Votre réponse')} : ${d['counterparty_response']}', style: const TextStyle(fontSize: 13, color: FpColors.navy)),
                        ],
                        if ((d['resolution'] ?? '').toString().isNotEmpty) ...[
                          const SizedBox(height: 6),
                          Text('${tr('Décision')} : ${d['resolution']}', style: const TextStyle(fontSize: 13)),
                        ],
                        if (open)
                          Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                            TextButton(onPressed: busy ? null : () => _respond(d), child: Text(tr('Répondre'))),
                            const SizedBox(width: 6),
                            FilledButton(onPressed: busy ? null : () => _refund(d), child: Text(busy ? '…' : tr('Rembourser'))),
                          ]),
                      ]),
                    ),
                  );
                }),
              ]),
            ),
    );
  }
}
