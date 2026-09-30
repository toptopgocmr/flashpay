import 'package:flutter/material.dart';
import '../../config/theme.dart';
import '../../widgets/fp_country.dart';
import '../../services/api_client.dart';
import '../../services/merchant_service.dart';
import '../../services/payment_service.dart';
import '../../services/pro_service.dart';
import '../../widgets/fp_ui.dart';
import '../../l10n/l10n.dart';

/// Sous-comptes caissiers (§3.2.3) : droits limités à l'encaissement,
/// rattachement à un point de vente, révocation immédiate (§15).
class CashiersScreen extends StatefulWidget {
  const CashiersScreen({super.key});

  @override
  State<CashiersScreen> createState() => _CashiersScreenState();
}

class _CashiersScreenState extends State<CashiersScreen> {
  final _service = MerchantToolsService();
  List<Map<String, dynamic>> _cashiers = [];
  List<dynamic> _outlets = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await Future.wait([_service.cashiers(), MerchantService().outlets()]);
      if (mounted) setState(() { _cashiers = r[0] as List<Map<String, dynamic>>; _outlets = r[1] as List<dynamic>; _loading = false; });
    } catch (e) {
      if (mounted) {
        setState(() => _loading = false);
        fpSnack(context, apiErrorMessage(e), error: true);
      }
    }
  }

  Future<void> _add() async {
    final name = TextEditingController();
    final phone = FpPhoneController();
    final password = TextEditingController();
    int? outletId;
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => Padding(
          padding: EdgeInsets.fromLTRB(20, 20, 20, 20 + MediaQuery.of(ctx).viewInsets.bottom),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(tr('Nouveau caissier'), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
            SizedBox(height: 12),
            TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom complet'))),
            SizedBox(height: 10),
            FpPhoneField(label: tr('Téléphone (identifiant de connexion)'), controller: phone),
            SizedBox(height: 10),
            TextField(controller: password, keyboardType: TextInputType.number, maxLength: 6, decoration: InputDecoration(labelText: tr('Code secret du caissier (4 chiffres)'), counterText: '')),
            SizedBox(height: 10),
            DropdownButtonFormField<int?>(
              value: outletId,
              decoration: InputDecoration(labelText: tr('Point de vente')),
              items: [
                DropdownMenuItem<int?>(value: null, child: Text(tr('Principal'))),
                ..._outlets.map((o) => DropdownMenuItem<int?>(value: fpInt((o as Map)['id']), child: Text('${o['name']}'))),
              ],
              onChanged: (v) => setSheet(() => outletId = v),
            ),
            SizedBox(height: 6),
            Text(tr('Le caissier se connecte via « Espace Caissier » : il peut uniquement encaisser et voir ses encaissements.'), style: TextStyle(fontSize: 12, color: Colors.black54)),
            SizedBox(height: 14),
            ElevatedButton(onPressed: () => Navigator.pop(ctx, true), child: Text(tr('Créer le caissier'))),
          ]),
        ),
      ),
    );
    if (ok != true) return;
    try {
      await _service.createCashier(name: name.text.trim(), phone: phone.international, password: password.text, outletId: outletId);
      _load();
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  Future<void> _toggle(Map<String, dynamic> c) async {
    final revoke = c['status'] == 'active';
    if (revoke) {
      final ok = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text(tr('Révoquer l\'accès ?')),
          content: Text('${c['user']?['full_name']} sera déconnecté immédiatement de tous ses appareils.'),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(tr('Annuler'))),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(tr('Révoquer'))),
          ],
        ),
      );
      if (ok != true) return;
    }
    try {
      await _service.updateCashier(fpInt(c['id']), revoke ? 'revoke' : 'reactivate');
      _load();
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(tr('Caissiers'))),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: FpColors.navy,
        foregroundColor: Colors.white,
        onPressed: _add,
        icon: Icon(Icons.person_add),
        label: Text(tr('Ajouter')),
      ),
      body: _loading
          ? Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(padding: EdgeInsets.fromLTRB(16, 16, 16, 90), children: [
                if (_cashiers.isEmpty) FpEmpty('Aucun caissier. Créez des sous-comptes pour vos employés.', icon: Icons.point_of_sale),
                ..._cashiers.map((c) {
                  final active = c['status'] == 'active';
                  return Card(child: ListTile(
                    leading: CircleAvatar(backgroundColor: active ? Color(0xFFDCFCE7) : Color(0xFFFEE2E2), child: Icon(Icons.point_of_sale, color: active ? FpColors.success : FpColors.danger)),
                    title: Text('${c['user']?['full_name'] ?? ''}'),
                    subtitle: Text('${c['user']?['phone'] ?? ''} · ${c['outlet']?['name'] ?? 'Principal'}\nAujourd\'hui : ${fpMoney(fpInt(c['today_collected']))}${c['last_login'] != null ? ' · vu ${fpDate(c['last_login'])}' : ''}'),
                    isThreeLine: true,
                    trailing: TextButton(onPressed: () => _toggle(c), child: Text(active ? 'Révoquer' : 'Réactiver', style: TextStyle(color: active ? FpColors.danger : FpColors.success))),
                  ));
                }),
              ]),
            ),
    );
  }
}
