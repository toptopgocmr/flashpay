import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';
import '../../widgets/fp_avatar.dart';
import '../../config/theme.dart';
import '../../models/user.dart';
import '../../providers/session_provider.dart';
import '../../services/api_client.dart';
import '../../services/auth_service.dart';
import '../../services/features_service.dart';
import '../../widgets/fp_design.dart';
import '../../widgets/fp_ui.dart';
import '../home_router.dart';
import '../../widgets/fp_country.dart';
import 'login_screen.dart' show kFpSecretLength;
import '../../l10n/l10n.dart';

/// Secteurs proposés à l'inscription marchand.
const kFpBusinessSectors = [
  'Alimentation / épicerie',
  'Restaurant / bar',
  'Boutique / habillement',
  'Téléphonie / électronique',
  'Pharmacie / santé',
  'Transport / carburant',
  'Services / artisanat',
  'Hôtellerie / tourisme',
  'Éducation',
  'Autre',
];

/// Inscription en deux étapes (maquettes) :
///  - Client   : 1. nom, numéro, code secret + confirmation, CGU
///               2. vérification d'identité (photo de profil, pièce recto / verso)
///  - Marchand : 1. boutique, secteur, adresse, gérant, numéro, code secret
///               2. documents (RCCM, photo boutique, pièce du gérant, photo du gérant)
/// Le compte est créé à la fin de l'étape 1 (numéro vérifié par OTP) ; les
/// pièces de l'étape 2 partent ensuite en validation KYC.
class RegisterScreen extends StatefulWidget {
  final FpProfile profile;
  const RegisterScreen({super.key, required this.profile});

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _nameCtrl = TextEditingController(); // nom complet / nom du gérant
  final _phoneCtrl = FpPhoneController();
  final _shopCtrl = TextEditingController();
  final _addressCtrl = TextEditingController();
  final _codeCtrl = TextEditingController();
  final _confirmCtrl = TextEditingController();
  final _birthDateCtrl = TextEditingController(); // affichage JJ/MM/AAAA
  final _birthPlaceCtrl = TextEditingController();
  DateTime? _birthDate;
  final _picker = ImagePicker();
  final _kyc = FeaturesService();

  String? _sector;
  bool _accepted = true;
  int _step = 1;
  bool _loading = false;
  String? _error;

  /// Pièces choisies à l'étape 2 : type KYC → fichier.
  final Map<String, XFile> _files = {};
  final Map<String, Uint8List> _previews = {}; // aperçus affichés dans les cadres

  bool get _isMerchant => widget.profile == FpProfile.merchant;

  // ------------------------------------------------------------ Étape 1
  String? _validateStep1() {
    if (_isMerchant) {
      if (_shopCtrl.text.trim().length < 2) return 'Indiquez le nom de la boutique.';
      if (_sector == null) return 'Choisissez le secteur d\'activité.';
      if (_addressCtrl.text.trim().length < 3) return 'Indiquez l\'adresse du point de vente.';
    }
    if (_nameCtrl.text.trim().length < 3) return _isMerchant ? 'Indiquez le nom du gérant.' : 'Indiquez votre nom complet.';
    if (_birthDate == null) return 'Indiquez votre date de naissance.';
    if (_birthPlaceCtrl.text.trim().length < 2) return 'Indiquez votre lieu de naissance.';
    if (_phoneCtrl.text.replaceAll(RegExp(r'\D'), '').length < 8) return 'Numéro mobile invalide.';
    final code = _codeCtrl.text;
    if (code.length != kFpSecretLength) return 'Le code secret doit contenir $kFpSecretLength chiffres.';
    if (RegExp(r'^(\d)\1+$').hasMatch(code) || ['1234', '4321', '0000', '123456'].contains(code)) {
      return 'Code trop simple : choisissez-en un autre.';
    }
    if (_confirmCtrl.text != code) return 'Les deux codes ne correspondent pas.';
    if (!_accepted) return 'Vous devez accepter les conditions d\'utilisation.';
    return null;
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

  Future<String?> _askOtp(String phone, String? debugCode) => fpAskOtp(context,
      title: tr('Vérification du numéro'), message: 'Saisissez le code reçu par SMS au $phone.', debugCode: debugCode);

  Future<void> _submitStep1() async {
    final invalid = _validateStep1();
    if (invalid != null) {
      setState(() => _error = invalid);
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
    });
    final phone = _phoneCtrl.international;
    try {
      final debug = await AuthService().requestOtp(phone, 'register');
      if (!mounted) return;
      final otp = await _askOtp(phone, debug);
      if (otp == null || otp.isEmpty) return;
      if (!mounted) return;
      await context.read<SessionProvider>().register(
            fullName: _nameCtrl.text.trim(),
            phone: phone,
            password: _codeCtrl.text,
            pin: _codeCtrl.text,
            profile: widget.profile,
            businessName: _isMerchant ? _shopCtrl.text.trim() : null,
            businessCategory: _isMerchant ? _sector : null,
            address: _isMerchant ? _addressCtrl.text.trim() : null,
            otp: otp,
            dateOfBirth: '${_birthDate!.year.toString().padLeft(4, '0')}-${_birthDate!.month.toString().padLeft(2, '0')}-${_birthDate!.day.toString().padLeft(2, '0')}',
            placeOfBirth: _birthPlaceCtrl.text.trim(),
          );
      if (mounted) setState(() => _step = 2);
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  // ------------------------------------------------------------ Étape 2
  Future<void> _pick(String type, {bool face = false}) async {
    final src = await showModalBottomSheet<ImageSource>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Wrap(children: [
          ListTile(leading: Icon(Icons.photo_camera_outlined), title: Text(tr('Prendre une photo')), onTap: () => Navigator.pop(ctx, ImageSource.camera)),
          ListTile(leading: Icon(Icons.photo_library_outlined), title: Text(tr('Choisir dans la galerie')), onTap: () => Navigator.pop(ctx, ImageSource.gallery)),
        ]),
      ),
    );
    if (src == null) return;
    final file = await _picker.pickImage(
      source: src,
      imageQuality: 70,
      maxWidth: 1800,
      preferredCameraDevice: face ? CameraDevice.front : CameraDevice.rear,
    );
    if (file == null) return;
    final bytes = await file.readAsBytes();
    if (!mounted) return;
    setState(() {
      _files[type] = file;
      _previews[type] = bytes;
    });
  }

  List<String> get _required => _isMerchant
      ? ['trade_register', 'shop_photo', 'id_card', 'id_card_back', 'profile_photo']
      : ['profile_photo', 'id_card', 'id_card_back'];

  Future<void> _submitStep2() async {
    final missing = _required.where((t) => !_files.containsKey(t)).toList();
    if (missing.isNotEmpty) {
      setState(() => _error = 'Ajoutez toutes les pièces marquées d\'un astérisque (*).');
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      for (final t in _required) {
        final f = _files[t]!;
        await _kyc.uploadKyc(type: t, bytes: _previews[t] ?? await f.readAsBytes(), filename: f.name);
      }
      FpPrivateImages.clear();
      if (!mounted) return;
      await context.read<SessionProvider>().refreshUser().catchError((_) {});
      if (!mounted) return;
      fpSnack(context, _isMerchant ? 'Boutique envoyée en validation (sous 48 h).' : 'Pièces envoyées. Vérification sous 48 h.');
      _goHome();
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _goHome() => Navigator.pushAndRemoveUntil(context, MaterialPageRoute(builder: (_) => homeFor(widget.profile)), (r) => false);

  // ------------------------------------------------------------ Vues
  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: _step == 1,
      child: Scaffold(
        appBar: AppBar(
          leading: _step == 1 ? BackButton() : null,
          automaticallyImplyLeading: _step == 1,
          actions: [
            if (_step == 2) TextButton(onPressed: _loading ? null : _goHome, child: Text(tr('Plus tard'))),
          ],
        ),
        body: SafeArea(
          top: false,
          child: SingleChildScrollView(child: _step == 1 ? _buildStep1() : _buildStep2()),
        ),
      ),
    );
  }

  Widget _buildStep1() {
    return FpAuthCard(children: [
      if (_isMerchant) FpStepHeader(step: 1),
      Text(_isMerchant ? 'Créer un compte marchand' : 'Créer un compte client',
          textAlign: _isMerchant ? TextAlign.start : TextAlign.center,
          style: TextStyle(fontSize: 27, fontWeight: FontWeight.w500, color: FpColors.ink)),
      SizedBox(height: 6),
      Text(_isMerchant ? 'Informations sur votre boutique' : 'Quelques informations pour commencer',
          textAlign: _isMerchant ? TextAlign.start : TextAlign.center, style: TextStyle(fontSize: 15, color: FpColors.muted)),
      SizedBox(height: 28),
      if (_isMerchant) ...[
        FpLineField(label: tr('Nom de la boutique'), controller: _shopCtrl, hint: 'Ex. Boutique Jennifer', capitalization: TextCapitalization.words),
        FpLineDropdown(label: tr('Secteur d\'activité'), value: _sector, items: kFpBusinessSectors, onChanged: (v) => setState(() => _sector = v)),
        FpLineField(label: tr('Adresse / point de vente'), controller: _addressCtrl, hint: 'Ville, quartier', capitalization: TextCapitalization.sentences),
        FpLineField(label: tr('Nom du gérant'), controller: _nameCtrl, hint: 'Nom complet', capitalization: TextCapitalization.words),
      ] else
        FpLineField(label: tr('Nom complet'), controller: _nameCtrl, hint: 'Ex. Jennifer Bakote', capitalization: TextCapitalization.words),
      FpLineField(label: tr('Date de naissance'), controller: _birthDateCtrl, hint: 'JJ/MM/AAAA', readOnly: true, onTap: _pickBirthDate),
      FpLineField(label: tr('Lieu de naissance'), controller: _birthPlaceCtrl, hint: 'Ex. Brazzaville', capitalization: TextCapitalization.words),
      FpPhoneLineField(controller: _phoneCtrl),
      Text(tr('Créer un code secret'), style: TextStyle(fontSize: 14.5, color: FpColors.ink)),
      SizedBox(height: 12),
      FpCodeBoxes(controller: _codeCtrl, length: kFpSecretLength),
      SizedBox(height: 22),
      Text(tr('Confirmer le code'), style: TextStyle(fontSize: 14.5, color: FpColors.ink)),
      SizedBox(height: 12),
      FpCodeBoxes(controller: _confirmCtrl, length: kFpSecretLength),
      SizedBox(height: 18),
      Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SizedBox(
          width: 32,
          height: 32,
          child: Checkbox(value: _accepted, onChanged: (v) => setState(() => _accepted = v ?? false)),
        ),
        SizedBox(width: 8),
        Expanded(
          child: Padding(
            padding: EdgeInsets.only(top: 5),
            child: Text(tr('J\'accepte les conditions d\'utilisation et la politique de confidentialité de FlashPay'),
                style: TextStyle(fontSize: 14, color: FpColors.ink, height: 1.4)),
          ),
        ),
      ]),
      SizedBox(height: 26),
      if (_error != null) ...[FpErrorBox(_error!), SizedBox(height: 14)],
      FpButton(_isMerchant ? 'Continuer' : 'Créer mon compte', onPressed: _submitStep1, loading: _loading),
      SizedBox(height: 16),
      Wrap(alignment: WrapAlignment.center, children: [
        Text(_isMerchant ? 'Déjà marchand ? ' : 'Déjà client ? ', style: TextStyle(fontSize: 15, color: FpColors.ink)),
        GestureDetector(
          onTap: () => Navigator.maybePop(context),
          child: Text(tr('Se connecter'), style: TextStyle(fontSize: 15, color: FpColors.red, fontWeight: FontWeight.w500)),
        ),
      ]),
    ]);
  }

  Widget _idRow() => Row(children: [
        Expanded(child: FpUploadBox(icon: Icons.badge_outlined, label: tr('Recto'), done: _files.containsKey('id_card'), preview: _previews['id_card'], onTap: () => _pick('id_card'))),
        SizedBox(width: 14),
        Expanded(child: FpUploadBox(icon: Icons.contact_mail_outlined, label: tr('Verso'), done: _files.containsKey('id_card_back'), preview: _previews['id_card_back'], onTap: () => _pick('id_card_back'))),
      ]);

  Widget _buildStep2() {
    final children = <Widget>[
      FpStepHeader(step: 2),
      Text(_isMerchant ? 'Documents de l\'entreprise' : 'Vérification d\'identité',
          style: TextStyle(fontSize: 27, fontWeight: FontWeight.w500, color: FpColors.ink)),
      SizedBox(height: 6),
      Text(_isMerchant ? 'Requis pour valider votre boutique' : 'Requis pour sécuriser votre compte',
          style: TextStyle(fontSize: 15, color: FpColors.muted)),
      SizedBox(height: 26),
    ];

    if (_isMerchant) {
      children.addAll([
        FpRequiredLabel('Registre de commerce (RCCM)'),
        FpUploadBox(icon: Icons.description_outlined, label: tr('Ajouter le document'), compact: true, done: _files.containsKey('trade_register'), preview: _previews['trade_register'], onTap: () => _pick('trade_register')),
        SizedBox(height: 20),
        FpRequiredLabel('Photo de la boutique'),
        FpUploadBox(icon: Icons.storefront_outlined, label: tr('Façade ou point de vente'), compact: true, done: _files.containsKey('shop_photo'), preview: _previews['shop_photo'], onTap: () => _pick('shop_photo')),
        SizedBox(height: 20),
        FpRequiredLabel('Pièce d\'identité du gérant'),
        _idRow(),
        SizedBox(height: 20),
        FpRequiredLabel('Photo du gérant'),
        Center(child: FpPhotoCircle(size: 110, done: _files.containsKey('profile_photo'), preview: _previews['profile_photo'], onTap: () => _pick('profile_photo', face: true))),
      ]);
    } else {
      children.addAll([
        Center(child: FpPhotoCircle(done: _files.containsKey('profile_photo'), preview: _previews['profile_photo'], onTap: () => _pick('profile_photo', face: true))),
        SizedBox(height: 10),
        Center(
          child: Text.rich(TextSpan(children: [
            TextSpan(text: tr('Photo de profil'), style: TextStyle(fontSize: 16, color: FpColors.ink)),
            TextSpan(text: ' *', style: TextStyle(fontSize: 16, color: FpColors.red)),
          ])),
        ),
        SizedBox(height: 28),
        FpRequiredLabel('Pièce d\'identité', hint: 'CNI, passeport ou permis de conduire'),
        _idRow(),
        SizedBox(height: 18),
        Text(tr('Assurez-vous que le document est net et que les 4 coins sont visibles.'),
            style: TextStyle(fontSize: 14, color: FpColors.muted, height: 1.4)),
      ]);
    }

    children.addAll([
      SizedBox(height: 30),
      if (_error != null) ...[FpErrorBox(_error!), SizedBox(height: 14)],
      FpButton(_isMerchant ? 'Valider ma boutique' : 'Valider mon compte', onPressed: _submitStep2, loading: _loading),
    ]);

    return FpAuthCard(children: children);
  }
}
