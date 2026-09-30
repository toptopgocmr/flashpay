import 'package:flutter/material.dart';
import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../services/payment_service.dart';
import '../../services/pro_service.dart';
import '../../widgets/fp_ui.dart';
import '../../l10n/l10n.dart';

/// Demandes d'approvisionnement du float (§3.1.2) : dépôt cash, virement ou
/// super-agent ; suivi du statut (en attente, validée, rejetée). Un
/// super-agent voit et valide ici les demandes de ses sous-agents.
class AgentFloatScreen extends StatefulWidget {
  const AgentFloatScreen({super.key});

  @override
  State<AgentFloatScreen> createState() => _AgentFloatScreenState();
}

class _AgentFloatScreenState extends State<AgentFloatScreen> {
  final _service = AgentFloatService();
  Map<String, dynamic>? _d;
  bool _loading = true;

  static const _methods = {'cash_deposit': 'Dépôt cash en agence', 'bank_transfer': 'Virement bancaire', 'super_agent': 'Super-agent'};
  static const _status = {'pending': 'En attente', 'approved': 'Validée', 'rejected': 'Rejetée', 'cancelled': 'Annulée'};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await _service.floatRequests();
      if (mounted) setState(() { _d = d; _loading = false; });
    } catch (e) {
      if (mounted) {
        setState(() => _loading = false);
        fpSnack(context, apiErrorMessage(e), error: true);
      }
    }
  }

  Future<void> _newRequest() async {
    final amount = TextEditingController();
    final proof = TextEditingController();
    final superCode = TextEditingController();
    String method = 'cash_deposit';
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => Padding(
          padding: EdgeInsets.fromLTRB(20, 20, 20, 20 + MediaQuery.of(ctx).viewInsets.bottom),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(tr('Demande d\'approvisionnement'), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
            SizedBox(height: 14),
            FpAmountField(controller: amount),
            SizedBox(height: 10),
            DropdownButtonFormField<String>(
              value: method,
              decoration: InputDecoration(labelText: tr('Mode d\'approvisionnement')),
              items: _methods.entries.map((e) => DropdownMenuItem(value: e.key, child: Text(e.value))).toList(),
              onChanged: (v) => setSheet(() => method = v ?? method),
            ),
            SizedBox(height: 10),
            if (method == 'super_agent')
              TextField(controller: superCode, decoration: InputDecoration(labelText: tr('Identifiant du super-agent (vide = mon super-agent)'), prefixIcon: Icon(Icons.badge_outlined)))
            else
              TextField(controller: proof, decoration: InputDecoration(labelText: tr('Référence du bordereau / virement'), prefixIcon: Icon(Icons.receipt_long))),
            SizedBox(height: 16),
            ElevatedButton(onPressed: () => Navigator.pop(ctx, true), child: Text(tr('Envoyer la demande'))),
          ]),
        ),
      ),
    );
    if (ok != true) return;
    try {
      await _service.createFloatRequest(amount: int.tryParse(amount.text.replaceAll(' ', '')) ?? 0, method: method, proof: proof.text.trim(), superAgentCode: superCode.text.trim());
      if (mounted) fpSnack(context, 'Demande envoyée. Vous serez notifié de la validation.');
      _load();
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  Future<void> _review(Map<String, dynamic> r, bool approve) async {
    String? reason;
    if (!approve) {
      final c = TextEditingController();
      reason = await showDialog<String>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text(tr('Motif du rejet')),
          content: TextField(controller: c, autofocus: true),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Annuler'))),
            FilledButton(onPressed: () => Navigator.pop(ctx, c.text.trim()), child: Text(tr('Rejeter'))),
          ],
        ),
      );
      if (reason == null || reason.isEmpty) return;
    }
    try {
      await _service.reviewFloatRequest(fpInt(r['id']), approve: approve, reason: reason);
      if (mounted) fpSnack(context, approve ? 'Float transféré au sous-agent.' : 'Demande rejetée.');
      _load();
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  String _tone(String? s) => switch (s) { 'approved' => 'ok', 'rejected' || 'cancelled' => 'err', _ => 'warn' };

  @override
  Widget build(BuildContext context) {
    final mine = ((_d?['mine'] ?? []) as List).cast<Map>();
    final toReview = ((_d?['to_review'] ?? []) as List).cast<Map>();
    final subAgents = ((_d?['sub_agents'] ?? []) as List).cast<Map>();
    return Scaffold(
      appBar: AppBar(title: Text(tr('Approvisionnement'))),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: FpColors.navy,
        foregroundColor: Colors.white,
        onPressed: _newRequest,
        icon: Icon(Icons.add),
        label: Text(tr('Nouvelle demande')),
      ),
      body: _loading
          ? Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(padding: EdgeInsets.fromLTRB(16, 8, 16, 90), children: [
                if (toReview.isNotEmpty) ...[
                  FpSectionTitle('À valider (mes sous-agents)'),
                  ...toReview.map((r) => Card(
                        child: ListTile(
                          title: Text('${r['agent']?['user']?['full_name'] ?? ''} · ${fpMoney(fpInt(r['amount']), '${r['currency'] ?? 'XAF'}')}'),
                          subtitle: Text(fpDate(r['created_at'])),
                          trailing: Wrap(spacing: 4, children: [
                            IconButton(icon: Icon(Icons.check_circle, color: FpColors.success), onPressed: () => _review(Map<String, dynamic>.from(r), true)),
                            IconButton(icon: Icon(Icons.cancel, color: FpColors.danger), onPressed: () => _review(Map<String, dynamic>.from(r), false)),
                          ]),
                        ),
                      )),
                ],
                if (subAgents.isNotEmpty) ...[
                  FpSectionTitle('Mes sous-agents'),
                  Card(child: Column(children: subAgents.map((a) => ListTile(
                        leading: Icon(Icons.storefront),
                        title: Text('${a['user']?['full_name'] ?? ''}'),
                        subtitle: Text('${a['agent_code'] ?? ''} · ${a['user']?['phone'] ?? ''}'),
                        trailing: Text(fpMoney(fpInt(a['user']?['wallet']?['balance']), '${a['user']?['wallet']?['currency'] ?? 'XAF'}')),
                      )).toList())),
                ],
                FpSectionTitle('Mes demandes'),
                if (mine.isEmpty) FpEmpty('Aucune demande d\'approvisionnement.'),
                ...mine.map((r) => Card(
                      child: ListTile(
                        leading: CircleAvatar(backgroundColor: Color(0xFFE3F3FF), child: Icon(Icons.account_balance_wallet, color: FpColors.navy)),
                        title: Text(fpMoney(fpInt(r['amount']), '${r['currency'] ?? 'XAF'}'), style: TextStyle(fontWeight: FontWeight.w800)),
                        subtitle: Text('${_methods[r['method']] ?? r['method']} · ${fpDate(r['created_at'])}${r['rejection_reason'] != null ? '\n${r['rejection_reason']}' : ''}'),
                        trailing: r['status'] == 'pending'
                            ? TextButton(onPressed: () async { await _service.cancelFloatRequest(fpInt(r['id'])); _load(); }, child: Text(tr('Annuler')))
                            : FpStatusChip(_status[r['status']] ?? '${r['status']}', tone: _tone(r['status']?.toString())),
                      ),
                    )),
              ]),
            ),
    );
  }
}
