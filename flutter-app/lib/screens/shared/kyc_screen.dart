import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';
import '../../config/theme.dart';
import '../../providers/session_provider.dart';
import '../../services/api_client.dart';
import '../../services/features_service.dart';
import '../../services/payment_service.dart';
import '../../widgets/fp_avatar.dart';
import '../../widgets/fp_design.dart';
import '../../widgets/fp_ui.dart';
import '../../l10n/l10n.dart';

/// Vérification d'identité et plafonds (§3.3.1, §12) : palier atteint,
/// consommation, envoi des pièces (photo de la pièce, selfie…).
class KycScreen extends StatefulWidget {
  const KycScreen({super.key});

  @override
  State<KycScreen> createState() => _KycScreenState();
}

class _KycScreenState extends State<KycScreen> {
  final _service = FeaturesService();
  final _picker = ImagePicker();
  final _idNumber = TextEditingController();
  final _birthDateCtrl = TextEditingController();
  final _birthPlaceCtrl = TextEditingController();
  DateTime? _birthDate;
  Map<String, dynamic>? _d;
  bool _busy = false;
  bool _savingBirthInfo = false;

  @override
  void initState() {
    super.initState();
    _load();
    final user = context.read<SessionProvider>().user;
    _birthPlaceCtrl.text = user?.placeOfBirth ?? '';
    final dob = user?.dateOfBirth;
    if (dob != null && dob.isNotEmpty) {
      final parsed = DateTime.tryParse(dob);
      if (parsed != null) {
        _birthDate = parsed;
        _birthDateCtrl.text = '${parsed.day.toString().padLeft(2, '0')}/${parsed.month.toString().padLeft(2, '0')}/${parsed.year}';
      }
    }
  }

  Future<void> _pickBirthDate() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _birthDate ?? DateTime(now.year - 18, now.month, now.day),
      firstDate: DateTime(now.year - 100),
      lastDate: DateTime(now.year - 16, now.month, now.day),
      helpText: 'Date de naissance',
    );
    if (picked != null) {
      setState(() {
        _birthDate = picked;
        _birthDateCtrl.text = '${picked.day.toString().padLeft(2, '0')}/${picked.month.toString().padLeft(2, '0')}/${picked.year}';
      });
    }
  }

  Future<void> _saveBirthInfo() async {
    if (_birthDate == null || _birthPlaceCtrl.text.trim().length < 2) {
      fpSnack(context, 'Indiquez votre date et votre lieu de naissance.', error: true);
      return;
    }
    setState(() => _savingBirthInfo = true);
    try {
      final iso = '${_birthDate!.year.toString().padLeft(4, '0')}-${_birthDate!.month.toString().padLeft(2, '0')}-${_birthDate!.day.toString().padLeft(2, '0')}';
      await context.read<SessionProvider>().updateProfile(dateOfBirth: iso, placeOfBirth: _birthPlaceCtrl.text.trim());
      if (mounted) fpSnack(context, 'Informations enregistrées.');
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    } finally {
      if (mounted) setState(() => _savingBirthInfo = false);
    }
  }

  Future<void> _load() async {
    try {
      final d = await _service.kyc();
      if (mounted) setState(() => _d = d);
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    }
  }

  Future<void> _upload(String type, ImageSource source) async {
    final file = await _picker.pickImage(source: source, imageQuality: 70, maxWidth: 1800, preferredCameraDevice: type == 'selfie' ? CameraDevice.front : CameraDevice.rear);
    if (file == null) return;
    setState(() => _busy = true);
    try {
      await _service.uploadKyc(type: type, bytes: await file.readAsBytes(), filename: file.name, idNumber: _idNumber.text.trim());
      if (type == 'profile_photo') FpPrivateImages.clear();
      if (mounted) fpSnack(context, 'Document envoyé. Vérification sous 48 h.');
      await _load();
      if (mounted) context.read<SessionProvider>().refreshUser();
    } catch (e) {
      if (mounted) fpSnack(context, apiErrorMessage(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _choose(String type) async {
    final src = await showModalBottomSheet<ImageSource>(
      context: context,
      builder: (ctx) => SafeArea(child: Wrap(children: [
        ListTile(leading: Icon(Icons.photo_camera), title: Text(tr('Prendre une photo')), onTap: () => Navigator.pop(ctx, ImageSource.camera)),
        if (type != 'selfie') ListTile(leading: Icon(Icons.photo_library), title: Text(tr('Choisir dans la galerie')), onTap: () => Navigator.pop(ctx, ImageSource.gallery)),
      ])),
    );
    if (src != null) _upload(type, src);
  }

  @override
  Widget build(BuildContext context) {
    final tier = fpInt(_d?['kyc_tier']);
    final limits = (_d?['limits'] ?? {}) as Map;
    final usage = (limits['usage'] ?? {}) as Map;
    final cur = '${limits['currency'] ?? 'XAF'}';
    final docs = ((_d?['documents'] ?? []) as List).cast<Map>();
    final rawTiers = limits['tiers'];
    final List<Map> tiers = rawTiers is List ? rawTiers.cast<Map>() : (rawTiers is Map ? rawTiers.values.cast<Map>().toList() : <Map>[]);
    final user = context.watch<SessionProvider>().user;
    final isPro = user?.isMerchant == true || user?.isAgent == true;

    String? statusOf(String type) => docs.where((d) => d['type'] == type).map((d) => '${d['status']}').firstOrNull;
    int? idOf(String type) => docs.where((d) => d['type'] == type).map((d) => fpInt(d['id'])).firstOrNull;
    bool lostOf(String type) => docs.where((d) => d['type'] == type).map((d) => d['has_file'] == false).firstOrNull ?? false;

    Widget docTile(String type, String label, String hint) {
      final lost = lostOf(type); // fichier perdu côté serveur : à renvoyer
      final st = lost ? 'rejected' : statusOf(type);
      final id = lost ? null : idOf(type);
      return ListTile(
        leading: id != null
            ? FpKycThumb(key: ValueKey('kyc-$id'), documentId: id, size: 48, round: type == 'profile_photo')
            : Icon(st == 'approved' ? Icons.check_circle : Icons.upload_file, color: st == 'approved' ? FpColors.success : FpColors.navy),
        title: Text(tr(label)),
        subtitle: Text(lost ? 'Photo à renvoyer, merci.' : hint, style: lost ? TextStyle(color: FpColors.danger) : null),
        trailing: st == null || st == 'rejected'
            ? FilledButton(onPressed: _busy ? null : () => _choose(type), child: Text(st == 'rejected' ? 'Renvoyer' : 'Envoyer'))
            : FpStatusChip(st == 'approved' ? 'Validé' : 'En cours', tone: st == 'approved' ? 'ok' : 'warn'),
      );
    }

    return Scaffold(
      appBar: AppBar(title: Text(tr('Identité & plafonds'))),
      body: _d == null
          ? Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(padding: EdgeInsets.all(16), children: [
                Card(
                  child: Padding(
                    padding: EdgeInsets.all(16),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(isPro ? 'Compte professionnel' : 'Palier $tier sur 2', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
                      if (!isPro) ...[
                        SizedBox(height: 8),
                        LinearProgressIndicator(value: (tier + 1) / 3, minHeight: 8, borderRadius: BorderRadius.circular(8), color: FpColors.teal),
                      ],
                      SizedBox(height: 12),
                      if (limits['per_operation'] != null) Text('Par opération : ${fpMoney(fpInt(limits['per_operation']), cur)}'),
                      if (limits['daily'] != null) Text('Aujourd\'hui : ${fpMoney(fpInt(usage['daily']), cur)} / ${fpMoney(fpInt(limits['daily']), cur)}'),
                      if (limits['monthly'] != null) Text('Ce mois : ${fpMoney(fpInt(usage['monthly']), cur)} / ${fpMoney(fpInt(limits['monthly']), cur)}'),
                      if (limits['max_balance'] != null) Text('Solde maximal : ${fpMoney(fpInt(limits['max_balance']), cur)}'),
                      if (!isPro) Text(limits['international'] == true ? 'Envois internationaux autorisés' : 'Envois internationaux : KYC complet requis', style: TextStyle(color: Colors.black54)),
                    ]),
                  ),
                ),
                if (tiers.isNotEmpty) ...[
                  FpSectionTitle('Paliers'),
                  ...tiers.asMap().entries.map((e) {
                    final t = e.value;
                    final reached = e.key <= tier;
                    return Card(child: ListTile(
                      leading: Icon(reached ? Icons.verified : Icons.lock_outline, color: reached ? FpColors.success : Colors.black38),
                      title: Text('${t['label']}'),
                      subtitle: Text('${t['kyc']} · ${fpMoney(fpInt(t['daily']), cur)}/jour · solde max ${fpMoney(fpInt(t['max_balance']), cur)}'),
                    ));
                  }),
                ],
                FpSectionTitle('Informations personnelles'),
                Card(
                  child: Padding(
                    padding: EdgeInsets.fromLTRB(16, 12, 16, 4),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      FpLineField(label: tr('Date de naissance'), controller: _birthDateCtrl, hint: 'JJ/MM/AAAA', readOnly: true, onTap: _pickBirthDate),
                      FpLineField(label: tr('Lieu de naissance'), controller: _birthPlaceCtrl, hint: 'Ex. Brazzaville', capitalization: TextCapitalization.words),
                      Align(
                        alignment: Alignment.centerRight,
                        child: Padding(
                          padding: EdgeInsets.only(bottom: 12),
                          child: FilledButton(
                            onPressed: _savingBirthInfo ? null : _saveBirthInfo,
                            child: _savingBirthInfo
                                ? SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                                : Text(tr('Enregistrer')),
                          ),
                        ),
                      ),
                    ]),
                  ),
                ),
                FpSectionTitle('Mes documents'),
                Card(child: Column(children: [
                  Padding(
                    padding: EdgeInsets.fromLTRB(16, 12, 16, 0),
                    child: TextField(controller: _idNumber, decoration: InputDecoration(labelText: tr('Numéro de la pièce (sert aussi à réinitialiser votre PIN)'))),
                  ),
                  docTile('id_card', 'Pièce d\'identité', 'CNI ou passeport, recto lisible'),
                  docTile('selfie', 'Selfie avec la pièce', 'Palier 2 : envois internationaux'),
                  if (user?.isMerchant == true) docTile('trade_register', 'Registre de commerce', 'RCCM ou équivalent'),
                  if (user?.isAgent == true) docTile('pos_proof', 'Justificatif du point de vente', 'Photo, bail ou patente'),
                  docTile('proof_of_address', 'Justificatif de domicile', 'Facture récente (facultatif)'),
                ])),
                if (_busy) Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator())),
              ]),
            ),
    );
  }
}
