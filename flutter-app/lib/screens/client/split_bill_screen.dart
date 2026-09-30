import 'package:flutter/material.dart';
import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../services/features_service.dart';
import '../../services/payment_service.dart';
import '../../widgets/fp_ui.dart';
import '../../widgets/fp_phone_chips.dart';
import '../../l10n/l10n.dart';

/// Partage de note (§3.5.2) : répartir une dépense à parts égales ou
/// personnalisées ; chaque participant règle sa part depuis son wallet ;
/// l'initiateur suit les parts réglées et relance.
class SplitBillScreen extends StatefulWidget {
  const SplitBillScreen({super.key});

  @override
  State<SplitBillScreen> createState() => _SplitBillScreenState();
}

class _SplitBillScreenState extends State<SplitBillScreen> {
  final _service = FeaturesService();
  Map<String, dynamic>? _d;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await _service.splits();
      if (mounted) setState(() => _d = d);
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  Future<void> _create() async {
    final title = TextEditingController();
    final total = TextEditingController();
    List<String> phones = [];
    bool includeSelf = true;
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => Padding(
          padding: EdgeInsets.fromLTRB(20, 20, 20, 20 + MediaQuery.of(ctx).viewInsets.bottom),
          child: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(tr('Partager une dépense'), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
            SizedBox(height: 12),
            TextField(controller: title, decoration: InputDecoration(labelText: tr('Objet (repas, taxi, cadeau commun…)'), prefixIcon: Icon(Icons.restaurant))),
            SizedBox(height: 10),
            FpAmountField(controller: total, label: tr('Montant total')),
            SizedBox(height: 10),
            FpPhoneChips(label: tr('Numéro d\'un participant'), onChanged: (l) => setSheet(() => phones = l)),
            Padding(
              padding: EdgeInsets.only(top: 4, left: 4),
              child: Text(tr('Hors Congo, ajoutez l\'indicatif : +237…, +221…'), style: TextStyle(fontSize: 12, color: Colors.black54)),
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              value: includeSelf,
              title: Text(tr('J\'inclus ma propre part')),
              onChanged: (v) => setSheet(() => includeSelf = v),
            ),
            ValueListenableBuilder<TextEditingValue>(
              valueListenable: total,
              builder: (_, v, __) {
                final t = int.tryParse(v.text.replaceAll(RegExp(r'\D'), '')) ?? 0;
                final n = phones.length + (includeSelf ? 1 : 0);
                final part = n == 0 ? 0 : (t / n).ceil();
                return Container(
                  padding: EdgeInsets.all(12),
                  decoration: BoxDecoration(color: FpColors.soft, borderRadius: BorderRadius.circular(12)),
                  child: Text(
                    n == 0 || t == 0
                        ? 'Répartition à parts égales. Chaque participant reçoit une demande de paiement.'
                        : '$n part(s) de ${fpMoney(part)} · chaque participant reçoit une demande de paiement.',
                    style: TextStyle(fontWeight: FontWeight.w600, color: FpColors.navy),
                  ),
                );
              },
            ),
            SizedBox(height: 14),
            ElevatedButton(
              onPressed: () {
                if (phones.isEmpty) {
                  fpSnack(ctx, tr('Ajoutez au moins un participant.'), error: true);
                  return;
                }
                Navigator.pop(ctx, true);
              },
              child: Text(tr('Envoyer les demandes')),
            ),
          ])),
        ),
      ),
    );
    if (ok != true) return;
    try {
      final participants = phones.map((p) => {'phone': p}).toList();
      await _service.createSplit({
        'title': title.text.trim(),
        'total_amount': int.tryParse(total.text.replaceAll(' ', '')) ?? 0,
        'mode': 'equal',
        'include_self': includeSelf,
        'participants': participants,
      });
      if (mounted) fpSnack(context, 'Demandes envoyées.');
      _load();
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  Future<void> _act(Future<void> Function() fn, String ok) async {
    try {
      await fn();
      if (mounted) fpSnack(context, ok);
      _load();
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final created = ((_d?['created'] ?? []) as List).cast<Map>();
    final toPay = ((_d?['to_pay'] ?? []) as List).cast<Map>();
    return Scaffold(
      appBar: AppBar(title: Text(tr('Partager une note'))),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: FpColors.navy,
        foregroundColor: Colors.white,
        onPressed: _create,
        icon: Icon(Icons.call_split),
        label: Text(tr('Nouveau partage')),
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(padding: EdgeInsets.fromLTRB(16, 8, 16, 90), children: [
          FpSectionTitle('À régler'),
          if (toPay.where((s) => s['status'] == 'pending').isEmpty) FpEmpty('Aucune part à régler.', icon: Icons.check_circle_outline),
          ...toPay.where((s) => s['status'] == 'pending').map((s) => Card(child: ListTile(
                title: Text('${s['split']?['title'] ?? ''} · ${fpMoney(fpInt(s['amount']), '${s['split']?['currency'] ?? 'XAF'}')}'),
                subtitle: Text('Demandé par ${s['split']?['creator']?['full_name'] ?? ''}'),
                trailing: Wrap(spacing: 4, children: [
                  TextButton(onPressed: () => _act(() => _service.declineSplitShare(fpInt(s['id'])), 'Part refusée.'), child: Text(tr('Refuser'))),
                  FilledButton(onPressed: () => _act(() => _service.paySplitShare(fpInt(s['id'])), 'Part réglée.'), child: Text(tr('Payer'))),
                ]),
              ))),
          FpSectionTitle('Mes partages'),
          if (created.isEmpty) FpEmpty('Aucun partage créé.'),
          ...created.map((s) {
            final shares = ((s['shares'] ?? []) as List).cast<Map>().where((x) => x['status'] != 'self').toList();
            final paid = shares.where((x) => x['status'] == 'paid').length;
            return Card(child: ExpansionTile(
              title: Text('${s['title']} · ${fpMoney(fpInt(s['total_amount']), '${s['currency'] ?? 'XAF'}')}'),
              subtitle: Text('$paid/${shares.length} part(s) réglée(s) · reste ${fpMoney(fpInt(s['pending_amount']), '${s['currency'] ?? 'XAF'}')}'),
              children: [
                ...shares.map((x) => ListTile(
                      dense: true,
                      title: Text('${x['user']?['full_name'] ?? x['phone']}'),
                      trailing: FpStatusChip(switch ('${x['status']}') { 'paid' => 'Payé', 'declined' => 'Refusé', _ => 'En attente' },
                          tone: switch ('${x['status']}') { 'paid' => 'ok', 'declined' => 'err', _ => 'warn' }),
                      subtitle: Text(fpMoney(fpInt(x['amount']), '${s['currency'] ?? 'XAF'}')),
                    )),
                if (s['status'] == 'open')
                  OverflowBar(children: [
                    TextButton(
                      onPressed: () => _act(() async {
                        final n = await _service.remindSplit(fpInt(s['id']));
                        if (n == 0) throw Exception('Relance déjà envoyée il y a moins d\'une heure.');
                      }, 'Relance envoyée.'),
                      child: Text(tr('Relancer')),
                    ),
                  ]),
              ],
            ));
          }),
        ]),
      ),
    );
  }
}
