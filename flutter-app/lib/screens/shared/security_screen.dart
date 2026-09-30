import 'package:flutter/material.dart';
import '../auth/account_recovery_screen.dart';
import 'package:provider/provider.dart';
import '../../config/theme.dart';
import '../../providers/session_provider.dart';
import '../../services/api_client.dart';
import '../../services/features_service.dart';
import '../../widgets/fp_ui.dart';
import '../../widgets/pin_sheet.dart';
import '../../l10n/l10n.dart';
import '../../l10n/language_picker.dart';

/// Sécurité (§4.5, §15, §17) : code PIN, appareils connectés (révocation),
/// langue de l'application.
class SecurityScreen extends StatefulWidget {
  const SecurityScreen({super.key});

  @override
  State<SecurityScreen> createState() => _SecurityScreenState();
}

class _SecurityScreenState extends State<SecurityScreen> {
  final _service = FeaturesService();
  List<Map<String, dynamic>> _devices = [];
  String? _currentDevice;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await _service.devices();
      final current = await ApiClient().deviceId();
      if (mounted) setState(() { _devices = d; _currentDevice = current; });
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  Future<void> _changePin(bool hasPin) async {
    String? current;
    if (hasPin) {
      current = await PinSheet.ask(context, title: tr('PIN actuel'));
      if (current == null || !mounted) return;
    }
    final next = await PinSheet.ask(context, confirmMode: true, title: hasPin ? 'Nouveau code PIN' : 'Créez votre code PIN');
    if (next == null) return;
    try {
      await _service.setPin(pin: next, currentPin: current);
      if (!mounted) return;
      fpSnack(context, 'Code PIN enregistré.');
      context.read<SessionProvider>().refreshUser();
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<SessionProvider>().user;
    final hasPin = user?.hasPin == true;
    return Scaffold(
      appBar: AppBar(title: Text(tr('Sécurité'))),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(padding: EdgeInsets.all(16), children: [
          Card(child: Column(children: [
            ListTile(
              leading: Icon(Icons.password),
              title: Text(hasPin ? 'Modifier mon code PIN' : 'Créer mon code PIN'),
              subtitle: Text(tr('Exigé pour confirmer chaque opération (envoi, paiement, retrait)')),
              trailing: Icon(Icons.chevron_right),
              onTap: () => _changePin(hasPin),
            ),
            if (hasPin) ...[
              Divider(height: 1),
              ListTile(
                leading: Icon(Icons.lock_reset),
                title: Text(tr("J'ai oublié mon code PIN")),
                subtitle: Text(tr('Recevez un code et choisissez un nouveau PIN')),
                trailing: Icon(Icons.chevron_right),
                onTap: () async {
                  await Navigator.push(context, MaterialPageRoute(builder: (_) => AccountRecoveryScreen(initialPhone: user?.phone != null ? '+${user!.phone.replaceAll('+', '')}' : null)));
                  if (context.mounted) context.read<SessionProvider>().refreshUser();
                },
              ),
            ],
            Divider(height: 1),
            ListTile(
              leading: Icon(Icons.translate),
              title: Text(tr('Langue')),
              subtitle: Text(L10n.languages[L10n.lang] ?? ''),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => fpShowLanguagePicker(context),
            ),
          ])),
          FpSectionTitle('Appareils connectés'),
          Text(tr('Une connexion depuis un nouvel appareil exige un code SMS et vous est notifiée. Déconnectez un appareil perdu ou inconnu.'), style: TextStyle(fontSize: 12, color: Colors.black54)),
          SizedBox(height: 8),
          ..._devices.map((d) {
            final revoked = d['revoked_at'] != null;
            final isCurrent = d['device_id'] == _currentDevice;
            return Card(child: ListTile(
              leading: Icon(d['platform'] == 'ios' ? Icons.phone_iphone : Icons.phone_android, color: revoked ? Colors.black26 : FpColors.navy),
              title: Text('${d['name'] ?? 'Appareil'}${isCurrent ? ' (cet appareil)' : ''}'),
              subtitle: Text(revoked ? 'Déconnecté le ${fpDate(d['revoked_at'])}' : 'Dernière activité ${fpDate(d['last_seen_at'])}'),
              trailing: revoked || isCurrent
                  ? null
                  : TextButton(
                      onPressed: () async {
                        await _service.revokeDevice(fpInt(d['id']));
                        _load();
                      },
                      child: Text(tr('Déconnecter'), style: TextStyle(color: FpColors.danger)),
                    ),
            ));
          }),
        ]),
      ),
    );
  }
}
