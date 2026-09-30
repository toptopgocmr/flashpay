import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../services/features_service.dart';
import '../../widgets/fp_ui.dart';
import '../../l10n/l10n.dart';

/// Aide & réclamations (§16) : base de connaissances, canaux de support,
/// tickets avec délai de réponse cible, suivi des contestations (§13.2).
class SupportScreen extends StatefulWidget {
  const SupportScreen({super.key});

  @override
  State<SupportScreen> createState() => _SupportScreenState();
}

class _SupportScreenState extends State<SupportScreen> {
  final _service = FeaturesService();
  Map<String, dynamic>? _faq;
  List<Map<String, dynamic>> _tickets = [];
  List<Map<String, dynamic>> _disputes = [];

  static const _categories = {
    'blocking_incident': 'Incident bloquant (paiement, compte)',
    'account': 'Mon compte',
    'kyc': 'Vérification d\'identité / plafonds',
    'security_report': 'Signalement de sécurité / fraude',
    'information': 'Demande d\'information',
  };

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await Future.wait([_service.faq(), _service.tickets(), _service.disputes()]);
      if (!mounted) return;
      setState(() {
        _faq = r[0] as Map<String, dynamic>;
        _tickets = r[1] as List<Map<String, dynamic>>;
        _disputes = r[2] as List<Map<String, dynamic>>;
      });
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  Future<void> _newTicket() async {
    String category = 'information';
    final subject = TextEditingController();
    final message = TextEditingController();
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => Padding(
          padding: EdgeInsets.fromLTRB(20, 20, 20, 20 + MediaQuery.of(ctx).viewInsets.bottom),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(tr('Contacter le support'), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
            SizedBox(height: 12),
            DropdownButtonFormField<String>(
              value: category,
              isExpanded: true,
              decoration: InputDecoration(labelText: tr('Type de demande')),
              items: _categories.entries.map((e) => DropdownMenuItem(value: e.key, child: Text(e.value))).toList(),
              onChanged: (v) => setSheet(() => category = v ?? category),
            ),
            SizedBox(height: 10),
            TextField(controller: subject, decoration: InputDecoration(labelText: tr('Objet'))),
            SizedBox(height: 10),
            TextField(controller: message, maxLines: 4, decoration: InputDecoration(labelText: tr('Votre message'))),
            SizedBox(height: 16),
            ElevatedButton(onPressed: () => Navigator.pop(ctx, true), child: Text(tr('Envoyer'))),
          ]),
        ),
      ),
    );
    if (ok != true) return;
    try {
      await _service.openTicket(category: category, subject: subject.text.trim(), message: message.text.trim());
      if (mounted) fpSnack(context, 'Demande envoyée. Réponse dans le centre de notifications.');
      _load();
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  Future<void> _reply(Map<String, dynamic> t) async {
    final c = TextEditingController();
    final text = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('${t['subject']}'),
        content: SizedBox(
          width: 400,
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            ...((t['messages'] ?? []) as List).cast<Map>().map((m) => Container(
                  margin: EdgeInsets.only(bottom: 6),
                  padding: EdgeInsets.all(8),
                  decoration: BoxDecoration(color: m['from_staff'] == true ? Color(0xFFE0E7FF) : FpColors.background, borderRadius: BorderRadius.circular(8)),
                  child: Text('${m['from_staff'] == true ? 'Support : ' : ''}${m['body']}'),
                )),
            TextField(controller: c, maxLines: 3, decoration: InputDecoration(labelText: tr('Répondre'))),
          ]),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Fermer'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, c.text.trim()), child: Text(tr('Envoyer'))),
        ],
      ),
    );
    if (text == null || text.isEmpty) return;
    await _service.replyTicket(fpInt(t['id']), text);
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final faq = ((_faq?['faq'] ?? []) as List).cast<Map>();
    final channels = ((_faq?['channels'] ?? []) as List).cast<Map>();
    return Scaffold(
      appBar: AppBar(title: Text(tr('Aide & réclamations'))),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: FpColors.navy,
        foregroundColor: Colors.white,
        onPressed: _newTicket,
        icon: Icon(Icons.chat_bubble_outline),
        label: Text(tr('Nous écrire')),
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(padding: EdgeInsets.fromLTRB(16, 8, 16, 90), children: [
          FpSectionTitle('Nous contacter'),
          Card(child: Column(children: channels.map((c) => ListTile(
                leading: Icon(switch ('${c['type']}') { 'phone' || 'pro' => Icons.call, 'agency' => Icons.store_mall_directory, _ => Icons.chat }),
                title: Text('${c['label']}'),
                subtitle: Text('${c['value']}${c['hours'] != null ? ' · ${c['hours']}' : ''}'),
                onTap: '${c['type']}' == 'phone' || '${c['type']}' == 'pro'
                    ? () => launchUrl(Uri.parse('tel:${'${c['value']}'.replaceAll(' ', '')}'))
                    : null,
              )).toList())),
          if (_disputes.isNotEmpty) ...[
            FpSectionTitle('Mes contestations'),
            ..._disputes.map((d) => Card(child: ListTile(
                  title: Text('${d['reason_label']} · ${d['reference']}'),
                  subtitle: Text('Opération ${d['transaction']?['reference'] ?? ''}\n${d['resolution'] ?? 'Réponse attendue avant le ${fpDate(d['sla_due_at'])}'}'),
                  isThreeLine: true,
                  trailing: FpStatusChip(switch ('${d['status']}') { 'resolved_refunded' => 'Remboursé', 'resolved_rejected' => 'Non retenu', 'investigating' => 'En instruction', _ => 'Ouvert' },
                      tone: switch ('${d['status']}') { 'resolved_refunded' => 'ok', 'resolved_rejected' => 'err', _ => 'warn' }),
                ))),
          ],
          if (_tickets.isNotEmpty) ...[
            FpSectionTitle('Mes demandes'),
            ..._tickets.map((t) => Card(child: ListTile(
                  title: Text('${t['subject']}'),
                  subtitle: Text('${t['reference']} · ${fpDate(t['created_at'])}'),
                  trailing: FpStatusChip(switch ('${t['status']}') { 'pending_user' => 'Réponse reçue', 'resolved' || 'closed' => 'Résolu', _ => 'En cours' },
                      tone: '${t['status']}' == 'pending_user' ? 'info' : ('${t['status']}' == 'open' ? 'warn' : 'ok')),
                  onTap: () => _reply(t),
                ))),
          ],
          FpSectionTitle('Questions fréquentes'),
          ...faq.map((q) => Card(child: ExpansionTile(title: Text('${q['q']}'), children: [Padding(padding: EdgeInsets.fromLTRB(16, 0, 16, 16), child: Text('${q['a']}'))]))),
        ]),
      ),
    );
  }
}

/// Contestation d'une opération depuis son détail (§13.2).
Future<void> showDisputeSheet(BuildContext context, int transactionId) async {
  String reason = 'unrecognized';
  final desc = TextEditingController();
  final ok = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    builder: (ctx) => StatefulBuilder(
      builder: (ctx, setSheet) => Padding(
        padding: EdgeInsets.fromLTRB(20, 20, 20, 20 + MediaQuery.of(ctx).viewInsets.bottom),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(tr('Contester cette opération'), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
          SizedBox(height: 12),
          ...kDisputeReasons.entries.map((e) => RadioListTile<String>(
                value: e.key,
                groupValue: reason,
                title: Text(e.value),
                dense: true,
                onChanged: (v) => setSheet(() => reason = v ?? reason),
              )),
          TextField(controller: desc, maxLines: 3, decoration: InputDecoration(labelText: tr('Précisions (facultatif)'))),
          SizedBox(height: 16),
          ElevatedButton(onPressed: () => Navigator.pop(ctx, true), child: Text(tr('Envoyer la contestation'))),
        ]),
      ),
    ),
  );
  if (ok != true || !context.mounted) return;
  try {
    final d = await FeaturesService().openDispute(transactionId: transactionId, reason: reason, description: desc.text.trim());
    if (context.mounted) fpSnack(context, 'Contestation ${d['reference']} enregistrée. Suivi dans Aide & réclamations.');
  } catch (e) {
    if (context.mounted) fpSnack(context, apiErrorMessage(e), error: true);
  }
}
