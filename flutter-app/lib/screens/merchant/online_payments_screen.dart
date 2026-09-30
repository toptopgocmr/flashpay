import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../services/pro_service.dart';
import '../../widgets/fp_ui.dart';
import '../../l10n/l10n.dart';

/// Paiement en ligne (§3.2.5, §4.7.1) : activation de l'option, clés API
/// sandbox / production, URL de webhook, documentation d'intégration.
class OnlinePaymentsScreen extends StatefulWidget {
  const OnlinePaymentsScreen({super.key});

  @override
  State<OnlinePaymentsScreen> createState() => _OnlinePaymentsScreenState();
}

class _OnlinePaymentsScreenState extends State<OnlinePaymentsScreen> {
  final _service = MerchantToolsService();
  Map<String, dynamic>? _d;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await _service.apiKeys();
      if (mounted) setState(() => _d = d);
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  Future<void> _issue(String env) async {
    final webhook = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(env == 'live' ? 'Clés de production' : 'Clés de test (sandbox)'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          Text(tr('Les clés existantes de cet environnement seront remplacées.')),
          SizedBox(height: 10),
          TextField(controller: webhook, keyboardType: TextInputType.url, decoration: InputDecoration(labelText: tr('URL de webhook (https://…)'))),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(tr('Générer'))),
        ],
      ),
    );
    if (ok != true) return;
    try {
      final r = await _service.issueApiKeys(environment: env, webhookUrl: webhook.text.trim());
      if (!mounted) return;
      await showDialog(
        context: context,
        barrierDismissible: false,
        builder: (ctx) => AlertDialog(
          title: Text(tr('Conservez ces clés')),
          content: SelectableText('Clé publique :\n${r['public_key']}\n\nClé secrète (affichée une seule fois) :\n${r['secret_key']}\n\nSecret webhook :\n${r['webhook_secret']}'),
          actions: [
            TextButton(onPressed: () => Clipboard.setData(ClipboardData(text: '${r['secret_key']}')), child: Text(tr('Copier la clé secrète'))),
            FilledButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('J\'ai conservé mes clés'))),
          ],
        ),
      );
      _load();
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  Widget _integrationCard(Map<String, dynamic> it) {
    final steps = ((it['steps'] ?? []) as List).cast<Map>();
    final progress = (it['progress'] ?? {}) as Map;
    final ok = ['sandbox_validated', 'live_pending', 'live'].contains(it['status']);
    return Card(
      child: Padding(
        padding: EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text(tr('Suivi de l\'intégration'), style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800))),
            FpStatusChip('${it['status_label']}', tone: ok ? 'ok' : 'warn'),
          ]),
          SizedBox(height: 8),
          LinearProgressIndicator(
            value: fpInt(progress['total']) == 0 ? 0 : fpInt(progress['done']) / fpInt(progress['total']),
            minHeight: 8,
            color: FpColors.success,
          ),
          SizedBox(height: 4),
          Text('${progress['done']}/${progress['total']} étapes obligatoires réussies en sandbox', style: TextStyle(fontSize: 12, color: Colors.black54)),
          SizedBox(height: 8),
          ...steps.map((st) => ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                leading: Icon(st['done'] == true ? Icons.check_circle : Icons.radio_button_unchecked, color: st['done'] == true ? FpColors.success : Colors.black26),
                title: Text('${st['label']}${st['required'] == true ? '' : ' (recommandé)'}'),
                subtitle: st['done'] == true ? null : Text('${st['help']}'),
              )),
          if (!ok)
            Text(tr('Les clés de production seront disponibles dès que toutes les étapes obligatoires seront réussies.'), style: TextStyle(fontSize: 12, color: Color(0xFFA16207))),
        ]),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final keys = ((_d?['keys'] ?? []) as List).cast<Map>();
    return Scaffold(
      appBar: AppBar(title: Text(tr('Paiement en ligne'))),
      body: _d == null
          ? Center(child: CircularProgressIndicator())
          : ListView(padding: EdgeInsets.all(16), children: [
              FpBanner(_d?['online_payments'] == true ? 'Paiement en ligne activé : vos clients paient avec leur wallet FlashPay sur votre site.' : 'Activez le paiement en ligne en générant vos clés API.',
                  icon: Icons.shopping_cart_checkout, color: FpColors.navy),
              // Suivi de l'intégration : étapes constatées automatiquement par FlashPay
              if (_d?['integration'] is Map) _integrationCard(Map<String, dynamic>.from(_d!['integration'] as Map)),
              ...['sandbox', 'live'].map((env) {
                final k = keys.where((x) => x['environment'] == env).firstOrNull;
                return Card(child: ListTile(
                  leading: Icon(env == 'live' ? Icons.public : Icons.science_outlined, color: env == 'live' ? FpColors.success : Color(0xFFA16207)),
                  title: Text(env == 'live' ? 'Production' : 'Sandbox (tests)'),
                  subtitle: Text(k == null ? 'Aucune clé' : '${k['public_key']}\nsk_…${k['secret_last4']} · webhook ${k['webhook_url'] ?? 'non défini'}'),
                  isThreeLine: k != null,
                  trailing: TextButton(onPressed: () => _issue(env), child: Text(k == null ? 'Générer' : 'Renouveler')),
                ));
              }),
              SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: () => launchUrl(Uri.parse('${_d?['docs_url']}'), mode: LaunchMode.externalApplication),
                icon: Icon(Icons.menu_book),
                label: Text(tr('Documentation d\'intégration (API, webhooks, widget, WooCommerce)')),
              ),
            ]),
    );
  }
}
