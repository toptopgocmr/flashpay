import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'config/navigation.dart';
import 'config/theme.dart';
import 'providers/session_provider.dart';
import 'screens/splash_screen.dart';

class FlashPayApp extends StatelessWidget {
  const FlashPayApp({super.key});

  @override
  Widget build(BuildContext context) {
    return ChangeNotifierProvider(
      create: (_) => SessionProvider(),
      child: MaterialApp(
        title: 'FlashPay',
        navigatorKey: fpNavigatorKey,
        debugShowCheckedModeBanner: false,
        theme: FpTheme.light(),
        home: const SplashScreen(),
        // Navigateur / tablette : l'app s'affiche dans un cadre de téléphone
        // centré (les maquettes sont pensées pour un écran mobile).
        builder: (context, child) {
          final mq = MediaQuery.of(context);
          if (mq.size.width <= 600 || child == null) return child ?? const SizedBox.shrink();
          const w = 430.0;
          final h = mq.size.height;
          return ColoredBox(
            color: const Color(0xFFDDE1E7),
            child: Center(
              child: Container(
                width: w,
                height: h,
                decoration: const BoxDecoration(boxShadow: [BoxShadow(color: Color(0x33000000), blurRadius: 30)]),
                child: MediaQuery(data: mq.copyWith(size: Size(w, h)), child: ClipRect(child: child)),
              ),
            ),
          );
        },
      ),
    );
  }
}
