import 'package:flutter/material.dart';
import '../../config/theme.dart';
import '../../services/merchant_service.dart';
import '../../l10n/l10n.dart';

/// Gestion des points de vente / sous-comptes (§2 "Gérer plusieurs points
/// de vente / sous-comptes").
class MerchantOutletsScreen extends StatefulWidget {
  const MerchantOutletsScreen({super.key});

  @override
  State<MerchantOutletsScreen> createState() => _MerchantOutletsScreenState();
}

class _MerchantOutletsScreenState extends State<MerchantOutletsScreen> {
  final _merchantService = MerchantService();
  List<dynamic> _outlets = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final data = await _merchantService.outlets();
    setState(() {
      _outlets = data;
      _loading = false;
    });
  }

  Future<void> _addOutlet() async {
    final nameCtrl = TextEditingController();
    final addressCtrl = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text(tr('Nouveau point de vente')),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(controller: nameCtrl, decoration: InputDecoration(labelText: tr('Nom du point de vente'))),
            SizedBox(height: 8),
            TextField(controller: addressCtrl, decoration: InputDecoration(labelText: tr('Adresse (optionnel)'))),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: Text(tr('Créer'))),
        ],
      ),
    );
    if (ok == true && nameCtrl.text.trim().isNotEmpty) {
      await _merchantService.createOutlet(name: nameCtrl.text.trim(), address: addressCtrl.text.trim());
      await _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(tr('Mes points de vente'))),
      floatingActionButton: FloatingActionButton(
        onPressed: _addOutlet,
        child: Icon(Icons.add),
      ),
      body: _loading
          ? Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: _outlets.isEmpty
                  ? ListView(children: [Padding(padding: EdgeInsets.all(32), child: Text(tr('Aucun point de vente. Ajoutez-en un avec le bouton +.')))])
                  : ListView.builder(
                      itemCount: _outlets.length,
                      itemBuilder: (context, i) {
                        final o = _outlets[i];
                        return ListTile(
                          leading: Icon(Icons.storefront, color: FpColors.navy),
                          title: Text(o['name'] ?? ''),
                          subtitle: Text(o['address'] ?? 'Sans adresse'),
                          trailing: Chip(label: Text(o['status'] ?? '')),
                        );
                      },
                    ),
            ),
    );
  }
}
