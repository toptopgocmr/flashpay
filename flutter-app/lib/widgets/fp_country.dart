import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../config/theme.dart';
import '../l10n/l10n.dart';

/// Pays couverts par FlashPay (miroir de backend/config/corridors.php).
class FpDialCountry {
  final String iso;
  final String name;
  final String dial; // sans « + »
  final String flag;
  final String hint;
  const FpDialCountry(this.iso, this.name, this.dial, this.flag, this.hint);
}

const kFpDialCountries = <FpDialCountry>[
  FpDialCountry('CG', 'Congo-Brazzaville', '242', '🇨🇬', '06 123 45 67'),
  FpDialCountry('CD', 'RD Congo', '243', '🇨🇩', '81 234 5678'),
  FpDialCountry('CM', 'Cameroun', '237', '🇨🇲', '6 71 23 45 67'),
  FpDialCountry('GA', 'Gabon', '241', '🇬🇦', '06 12 34 56'),
  FpDialCountry('TD', 'Tchad', '235', '🇹🇩', '66 12 34 56'),
  FpDialCountry('CF', 'Centrafrique', '236', '🇨🇫', '72 12 34 56'),
  FpDialCountry('GQ', 'Guinée équatoriale', '240', '🇬🇶', '222 123 456'),
  FpDialCountry('SN', 'Sénégal', '221', '🇸🇳', '77 123 45 67'),
  FpDialCountry('CI', "Côte d'Ivoire", '225', '🇨🇮', '07 12 34 56 78'),
  FpDialCountry('ML', 'Mali', '223', '🇲🇱', '76 12 34 56'),
  FpDialCountry('BF', 'Burkina Faso', '226', '🇧🇫', '70 12 34 56'),
  FpDialCountry('BJ', 'Bénin', '229', '🇧🇯', '01 97 12 34 56'),
  FpDialCountry('TG', 'Togo', '228', '🇹🇬', '90 12 34 56'),
  FpDialCountry('NE', 'Niger', '227', '🇳🇪', '96 12 34 56'),
  FpDialCountry('GW', 'Guinée-Bissau', '245', '🇬🇼', '955 123 456'),
  FpDialCountry('GN', 'Guinée', '224', '🇬🇳', '621 12 34 56'),
];

/// Numéro saisi → format international « +<indicatif><numéro> ».
/// Un numéro déjà international (+… ou 00…) est conservé tel quel.
String fpPhoneWithDial(String raw, String dial) {
  final t = raw.trim();
  final digits = t.replaceAll(RegExp(r'\D'), '');
  if (t.startsWith('+')) return '+$digits';
  if (t.startsWith('00')) return '+${digits.substring(2)}';
  if (digits.startsWith(dial) && digits.length > dial.length + 7) return '+$digits';
  return '+$dial$digits';
}

/// Contrôleur de saisie d'un numéro : texte + pays choisi (drapeau / indicatif).
class FpPhoneController extends TextEditingController {
  FpDialCountry _country;
  FpPhoneController({String? text, FpDialCountry? country})
      : _country = country ?? kFpDialCountries.first,
        super(text: text);

  FpDialCountry get country => _country;
  set country(FpDialCountry c) {
    _country = c;
    notifyListeners();
  }

  /// Numéro complet « +<indicatif><numéro> » (vide si rien n'est saisi).
  String get international => text.trim().isEmpty ? '' : fpPhoneWithDial(text, _country.dial);

  /// Remplit à partir d'un numéro international (« +237… », « 242… ») :
  /// le pays est déduit de l'indicatif.
  void setInternational(String raw) {
    final d = raw.replaceAll(RegExp(r'\D'), '');
    for (final c in kFpDialCountries) {
      if (d.startsWith(c.dial) && d.length > c.dial.length + 6) {
        _country = c;
        text = d.substring(c.dial.length);
        return;
      }
    }
    text = raw.trim();
  }

  /// Nombre de chiffres saisis (hors indicatif).
  int get digitCount => text.replaceAll(RegExp(r'\D'), '').length;
}

/// Feuille de choix du pays.
Future<FpDialCountry?> fpPickDialCountry(BuildContext context, FpDialCountry current) => showModalBottomSheet<FpDialCountry>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      builder: (ctx) => SafeArea(
        child: ConstrainedBox(
          constraints: BoxConstraints(maxHeight: MediaQuery.of(ctx).size.height * 0.75),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Padding(
              padding: EdgeInsets.fromLTRB(20, 0, 20, 8),
              child: Text(tr('Choisissez le pays'), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700, color: FpColors.ink)),
            ),
            Flexible(
              child: ListView(
                shrinkWrap: true,
                children: [
                  for (final c in kFpDialCountries)
                    ListTile(
                      leading: Text(c.flag, style: TextStyle(fontSize: 26)),
                      title: Text(c.name),
                      trailing: Text('+${c.dial}', style: TextStyle(fontWeight: FontWeight.w600, color: FpColors.muted)),
                      selected: c.iso == current.iso,
                      selectedColor: FpColors.navy,
                      onTap: () => Navigator.pop(ctx, c),
                    ),
                ],
              ),
            ),
          ]),
        ),
      ),
    );

/// Bouton drapeau + indicatif (ouvre la liste des pays).
class _DialButton extends StatelessWidget {
  final FpPhoneController controller;
  final double fontSize;
  final EdgeInsets padding;
  const _DialButton({required this.controller, required this.fontSize, required this.padding});

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: () async {
          final c = await fpPickDialCountry(context, controller.country);
          if (c != null) controller.country = c;
        },
        borderRadius: BorderRadius.circular(8),
        child: Padding(
          padding: padding,
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            Text(controller.country.flag, style: TextStyle(fontSize: fontSize + 4)),
            SizedBox(width: 6),
            Text('+${controller.country.dial}', style: TextStyle(fontSize: fontSize, color: FpColors.ink)),
            Icon(Icons.arrow_drop_down, color: FpColors.muted),
          ]),
        ),
      );
}

final _phoneFormatters = [FilteringTextInputFormatter.allow(RegExp(r'[0-9 +]'))];

/// Champ « Numéro mobile » souligné (écrans d'accueil : connexion, inscription…).
class FpPhoneLineField extends StatelessWidget {
  final String label;
  final FpPhoneController controller;
  final ValueChanged<String>? onChanged;
  const FpPhoneLineField({super.key, this.label = 'Numéro mobile', required this.controller, this.onChanged});

  @override
  Widget build(BuildContext context) => ListenableBuilder(
        listenable: controller,
        builder: (context, _) => Padding(
          padding: EdgeInsets.only(bottom: 22),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr(label), style: TextStyle(fontSize: 14.5, color: FpColors.ink)),
            TextField(
              controller: controller,
              keyboardType: TextInputType.phone,
              inputFormatters: _phoneFormatters,
              onChanged: onChanged,
              style: TextStyle(fontSize: 18, color: FpColors.ink),
              decoration: InputDecoration(
                hintText: controller.country.hint,
                hintStyle: TextStyle(color: Color(0xFF9CA3AF), fontSize: 18),
                prefixIcon: _DialButton(controller: controller, fontSize: 18, padding: EdgeInsets.only(right: 10, top: 10, bottom: 10)),
                prefixIconConstraints: BoxConstraints(minWidth: 0, minHeight: 0),
                filled: false,
                contentPadding: EdgeInsets.symmetric(vertical: 12),
                border: UnderlineInputBorder(borderSide: BorderSide(color: FpColors.line, width: 1.4)),
                enabledBorder: UnderlineInputBorder(borderSide: BorderSide(color: FpColors.line, width: 1.4)),
                focusedBorder: UnderlineInputBorder(borderSide: BorderSide(color: FpColors.navy, width: 1.8)),
              ),
            ),
          ]),
        ),
      );
}

/// Champ téléphone encadré (formulaires, boîtes de dialogue).
class FpPhoneField extends StatelessWidget {
  final String label;
  final FpPhoneController controller;
  final ValueChanged<String>? onChanged;
  const FpPhoneField({super.key, required this.label, required this.controller, this.onChanged});

  @override
  Widget build(BuildContext context) => ListenableBuilder(
        listenable: controller,
        builder: (context, _) => TextField(
          controller: controller,
          keyboardType: TextInputType.phone,
          inputFormatters: _phoneFormatters,
          onChanged: onChanged,
          decoration: InputDecoration(
            labelText: label,
            hintText: controller.country.hint,
            prefixIcon: _DialButton(controller: controller, fontSize: 15, padding: EdgeInsets.only(left: 12, right: 6)),
          ),
        ),
      );
}

/// Code de vérification affiché dans l'app tant que l'envoi réel des SMS
/// n'est pas branché (serveur : FLASHPAY_OTP_DEBUG=true renvoie le code).
class FpOtpCodeCard extends StatelessWidget {
  final String code;
  final String title;
  const FpOtpCodeCard({super.key, required this.code, this.title = 'Votre code de vérification'});

  @override
  Widget build(BuildContext context) => Container(
        width: double.infinity,
        padding: EdgeInsets.symmetric(vertical: 12, horizontal: 14),
        decoration: BoxDecoration(color: FpColors.soft, borderRadius: BorderRadius.circular(14)),
        child: Column(children: [
          Text(tr(title), style: TextStyle(fontSize: 13, color: FpColors.muted)),
          SizedBox(height: 4),
          SelectableText(code, style: TextStyle(fontSize: 30, fontWeight: FontWeight.w800, letterSpacing: 6, color: FpColors.navy)),
          SizedBox(height: 2),
          Text(tr('Code déjà rempli pour vous'), style: TextStyle(fontSize: 12, color: FpColors.muted)),
        ]),
      );
}

/// Saisie d'un code SMS. Tant que l'envoi réel des SMS n'est pas activé,
/// le serveur renvoie le code (FLASHPAY_OTP_DEBUG=true) : il est affiché
/// en clair et pré-rempli.
Future<String?> fpAskOtp(BuildContext context, {required String title, required String message, String? debugCode}) {
  final ctrl = TextEditingController(text: debugCode ?? '');
  return showDialog<String>(
    context: context,
    barrierDismissible: false,
    builder: (ctx) => AlertDialog(
      title: Text(tr(title)),
      content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(message),
        if (debugCode != null) ...[
          SizedBox(height: 14),
          FpOtpCodeCard(code: debugCode),
        ],
        SizedBox(height: 14),
        TextField(
          controller: ctrl,
          autofocus: debugCode == null,
          keyboardType: TextInputType.number,
          maxLength: 6,
          decoration: InputDecoration(labelText: tr('Code SMS'), counterText: ''),
        ),
      ]),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Annuler'))),
        FilledButton(onPressed: () => Navigator.pop(ctx, ctrl.text.trim()), child: Text(tr('Valider'))),
      ],
    ),
  );
}
