plugins {
    id("com.android.application")
    id("dev.flutter.flutter-gradle-plugin")
}
val storeFilePath = System.getenv("F360_STORE_FILE")
val storePasswordValue = System.getenv("F360_STORE_PASSWORD")
val keyAliasValue = System.getenv("F360_KEY_ALIAS")
val keyPasswordValue = System.getenv("F360_KEY_PASSWORD")
val releaseSigningReady = listOf(storeFilePath, storePasswordValue, keyAliasValue, keyPasswordValue).all { !it.isNullOrBlank() }
android {
    namespace = "com.finance360.app"
    compileSdk = 36
    ndkVersion = flutter.ndkVersion
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    defaultConfig {
        applicationId = "com.finance360.app"
        minSdk = 24
        targetSdk = 36
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }
    flavorDimensions += "environment"
    productFlavors {
        create("staging") { dimension = "environment"; applicationIdSuffix = ".staging"; versionNameSuffix = "-staging" }
        create("production") { dimension = "environment" }
    }
    signingConfigs {
        if (releaseSigningReady) {
            create("ownerRelease") {
                storeFile = file(storeFilePath!!)
                storePassword = storePasswordValue
                keyAlias = keyAliasValue
                keyPassword = keyPasswordValue
            }
        }
    }
    buildTypes {
        release {
            if (releaseSigningReady) signingConfig = signingConfigs.getByName("ownerRelease")
        }
    }
}
kotlin { compilerOptions { jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17 } }
flutter { source = "../.." }
gradle.taskGraph.whenReady {
    if (allTasks.any { it.name.contains("Release", ignoreCase = true) } && !releaseSigningReady) {
        throw GradleException("Owner-controlled release signing is required. No debug-key release fallback is permitted.")
    }
}
