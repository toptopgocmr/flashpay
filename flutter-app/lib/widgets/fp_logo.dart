import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import '../l10n/l10n.dart';

/// Logo FlashPay (deux flèches rouge / bleue).
/// [chip] = true : pastille blanche arrondie, pour les fonds sombres
/// (la flèche bleue reste lisible sur le bleu marine FlashPay).
class FpLogo extends StatelessWidget {
  const FpLogo({super.key, this.size = 48, this.chip = false});

  final double size;
  final bool chip;

  static const asset = 'assets/images/flashpay_logo.svg';

  @override
  Widget build(BuildContext context) {
    final logo = SvgPicture.asset(asset, width: size, height: size, semanticsLabel: 'FlashPay');
    if (!chip) return logo;

    return Container(
      padding: EdgeInsets.all(size * 0.14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(size * 0.28),
      ),
      child: logo,
    );
  }
}

/// Titre d'AppBar : logo + texte.
class FpAppBarTitle extends StatelessWidget {
  const FpAppBarTitle(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        FpLogo(size: 26, chip: true),
        SizedBox(width: 10),
        Flexible(child: Text(tr(text), overflow: TextOverflow.ellipsis)),
      ],
    );
  }
}
