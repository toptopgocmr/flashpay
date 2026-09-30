import 'package:flutter/material.dart';
import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../services/payment_service.dart';
import '../../services/pro_service.dart';
import '../../widgets/fp_ui.dart';
import '../../l10n/l10n.dart';

/// Caisse agent (§3.1.6, §4.6.5) : journal avec solde avant / après,
/// ventilation du float par origine, rapprochement journalier et barème.
class AgentCashbookScreen extends StatefulWidget {
  const AgentCashbookScreen({super.key});

  @override
  State<AgentCashbookScreen> createState() => _AgentCashbookScreenState();
}

class _AgentCashbookScreenState extends State<AgentCashbookScreen> {
  final _service = AgentFloatService();
  List<Map<String, dynamic>> _journal = [];
  Map<String, dynamic>? _rec;
  Map<String, dynamic>? _float;
  Map<String, dynamic>? _commissions;
  DateTime _day = DateTime.now();
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  String get _dayIso => '${_day.year}-${_day.month.toString().padLeft(2, '0')}-${_day.day.toString().padLeft(2, '0')}';

  Future<void> _load() async {
    try {
      final r = await Future.wait([_service.journal(), _service.reconciliation(date: _dayIso), _service.float(), _service.commissions()]);
      if (!mounted) return;
      setState(() {
        _journal = r[0] as List<Map<String, dynamic>>;
        _rec = r[1] as Map<String, dynamic>;
        _float = r[2] as Map<String, dynamic>;
        _commissions = r[3] as Map<String, dynamic>;
        _loading = false;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _loading = false);
        fpSnack(context, apiErrorMessage(e), error: true);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final cur = '${_rec?['currency'] ?? 'XAF'}';
    return DefaultTabController(
      length: 3,
      child: Scaffold(
        appBar: AppBar(
          title: Text(tr('Ma caisse')),
          bottom: TabBar(
              tabs: [Tab(text: tr('Journal')), Tab(text: tr('Rapprochement')), Tab(text: tr('Float & barème'))]),
        ),
        body: _loading
            ? Center(child: CircularProgressIndicator())
            : TabBarView(children: [
                RefreshIndicator(
                  onRefresh: _load,
                  child: _journal.isEmpty
                      ? ListView(children: [FpEmpty('Aucune opération.')])
                      : ListView.separated(
                          itemCount: _journal.length,
                          separatorBuilder: (_, __) => Divider(height: 1),
                          itemBuilder: (_, i) {
                            final j = _journal[i];
                            final credit = j['direction'] == 'credit';
                            return ListTile(
                              title: Text('${j['label']}${j['counterparty'] != null ? ' · ${j['counterparty']}' : ''}', maxLines: 1, overflow: TextOverflow.ellipsis),
                              subtitle: Text('${fpDate(j['created_at'])}\nAvant ${fpMoney(fpInt(j['balance_before']), cur)} → après ${fpMoney(fpInt(j['balance_after']), cur)}'),
                              isThreeLine: true,
                              trailing: Text('${credit ? '+' : '−'}${fpMoney(fpInt(j['amount']), cur)}',
                                  style: TextStyle(fontWeight: FontWeight.w800, color: credit ? FpColors.success : FpColors.navy)),
                            );
                          },
                        ),
                ),
                ListView(padding: EdgeInsets.all(16), children: [
                  Row(children: [
                    IconButton(icon: Icon(Icons.chevron_left), onPressed: () { setState(() => _day = _day.subtract(Duration(days: 1))); _load(); }),
                    Expanded(child: Text('Journée du ${fpDate(_day.toIso8601String(), time: false)}', textAlign: TextAlign.center, style: TextStyle(fontWeight: FontWeight.w700))),
                    IconButton(icon: Icon(Icons.chevron_right), onPressed: _day.isBefore(DateTime.now().subtract(Duration(hours: 20))) ? () { setState(() => _day = _day.add(Duration(days: 1))); _load(); } : null),
                  ]),
                  Card(child: Column(children: [
                    _row('Solde d\'ouverture', fpMoney(fpInt(_rec?['opening_balance']), cur)),
                    _row('Entrées (float, retraits clients, commissions)', '+ ${fpMoney(fpInt(_rec?['credits']), cur)}'),
                    _row('Sorties (dépôts clients, transferts)', '− ${fpMoney(fpInt(_rec?['debits']), cur)}'),
                    Divider(height: 1),
                    _row('Solde de clôture', fpMoney(fpInt(_rec?['closing_balance']), cur), bold: true),
                  ])),
                  SizedBox(height: 8),
                  FpBanner(_rec?['balanced'] == true ? 'Rapprochement équilibré.' : 'Écart détecté : contactez FlashPay.',
                      icon: _rec?['balanced'] == true ? Icons.verified : Icons.warning_amber, color: _rec?['balanced'] == true ? FpColors.success : FpColors.danger),
                  FpSectionTitle('Espèces en caisse (théorique)'),
                  Card(child: Column(children: [
                    _row('Cash reçu des clients (dépôts)', '+ ${fpMoney(fpInt(_rec?['cash_received']), cur)}'),
                    _row('Cash remis aux clients (retraits)', '− ${fpMoney(fpInt(_rec?['cash_given']), cur)}'),
                    _row('Opérations en attente', '${_rec?['pending_operations'] ?? 0}'),
                  ])),
                ]),
                ListView(padding: EdgeInsets.all(16), children: [
                  FpSectionTitle('Origine du float (30 jours)'),
                  Card(child: Column(children: [
                    for (final e in ((_float?['origins'] ?? {}) as Map).entries)
                      _row({'approvisionnements': 'Approvisionnements', 'retours_cash_out': 'Retours de cash-out', 'transferts_entrants': 'Transferts entrants', 'commissions': 'Commissions', 'sorties': 'Sorties'}[e.key] ?? '${e.key}', fpMoney(fpInt(e.value), cur)),
                  ])),
                  FpSectionTitle('Barème de commissions'),
                  Card(child: Column(children: [
                    for (final r in ((_commissions?['rules'] ?? []) as List).cast<Map>())
                      ListTile(
                        dense: true,
                        title: Text('${(_commissions?['labels'] ?? {})[r['operation']] ?? r['operation']}'),
                        subtitle: Text('De ${fpMoney(fpInt(r['min_amount']), cur)}${r['max_amount'] != null ? ' à ${fpMoney(fpInt(r['max_amount']), cur)}' : ' et plus'}'),
                        trailing: Text(r['type'] == 'fixed' ? fpMoney(fpInt(r['value']), cur) : '${r['value']} %${r['type'] == 'fee_share' ? ' des frais' : ''}', style: TextStyle(fontWeight: FontWeight.w700)),
                      ),
                  ])),
                ]),
              ]),
      ),
    );
  }

  Widget _row(String label, String value, {bool bold = false}) => ListTile(
        dense: true,
        title: Text(tr(label)),
        trailing: Text(value, style: TextStyle(fontWeight: bold ? FontWeight.w800 : FontWeight.w600)),
      );
}
