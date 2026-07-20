pipeline {
    agent {
        node { label 'Linux-2-8' }
    }
    
    environment {
        DOCKERHUB_USER    = "devopsrwinteractive"
        BASE_REPO_NAME    = "devopsrwinteractive/laravel83-infra-base"
        APP_REPO_NAME     = "devopsrwinteractive/piquet-laravel-prod"
        REGISTRY_CREDS    = 'dockerhub'
        DOCKER_BUILDKIT   = '1' 
        // Removidas variáveis do Vault
    }    
    
    stages {
        stage('Cleanup & Checkout') {
            steps {
                script {
                    cleanWs()
                    sh "docker system prune -f"
                }
                
                checkout([$class: 'GitSCM', 
                    branches: [[name: 'production']], 
                    userRemoteConfigs: [[credentialsId: 'gitlab-rwi', url: 'https://gitlab.rwinteractive.net/piquet/backend.git']],
                    extensions: [[$class: 'RelativeTargetDirectory', relativeTargetDir: '.']]
                ])
                
                dir('payshop-sdk') {
                    git branch: 'production',
                        credentialsId: 'gitlab-rwi',
                        url: 'https://gitlab.rwinteractive.net/piquet/payshop-sdk.git'
                }
                
                script {
                    echo "--- Verificando integridade dos arquivos ---"
                    sh "ls -la infra/entrypoint.sh"
                    
                    // Ajuste para o composer encontrar o SDK na pasta local durante o build
                    sh "sed -i 's|\"url\": \"../payshop-sdk\"|\"url\": \"./payshop-sdk\"|g' composer.json"
                }
            }
        }

        stage('Prepare Golden Image') {
            steps {
                script {
                    def baseDoc = 'infra/docker/base.Dockerfile'
                    def baseHash = sh(script: "md5sum ${baseDoc} | cut -d ' ' -f 1", returnStdout: true).trim()
                    env.GOLDEN_IMAGE = "${BASE_REPO_NAME}:${baseHash}"
                    
                    docker.withRegistry('', REGISTRY_CREDS) {
                        try {
                            sh "docker pull ${env.GOLDEN_IMAGE}"
                        } catch (e) {
                            sh "docker build -t ${env.GOLDEN_IMAGE} -t ${BASE_REPO_NAME}:latest -f ${baseDoc} ."
                            sh "docker push ${env.GOLDEN_IMAGE}"
                            sh "docker push ${BASE_REPO_NAME}:latest"
                        }
                    }
                }
            }
        }
        
        stage('Build & Push App Image') {
            steps {
                script {
                    docker.withRegistry('', REGISTRY_CREDS) {
                        sh """
                            docker build \
                            --build-arg BASE_IMAGE=${env.GOLDEN_IMAGE} \
                            --tag ${APP_REPO_NAME}:latest \
                            --tag ${APP_REPO_NAME}:${BUILD_NUMBER} \
                            -f infra/docker/app.Dockerfile .
                        """
                        sh "docker push ${APP_REPO_NAME}:${BUILD_NUMBER}"
                        sh "docker push ${APP_REPO_NAME}:latest"
                    }
                }
            }
        }
      
        stage('Security Scan (Trivy)') {
            steps {
                script {
                    def workspacePath = pwd()
                    withCredentials([usernamePassword(credentialsId: REGISTRY_CREDS, usernameVariable: 'U', passwordVariable: 'P')]) {
                        sh """
                            docker run --rm \
                            -v /var/run/docker.sock:/var/run/docker.sock \
                            -v "${workspacePath}":/workspace \
                            aquasec/trivy:0.50.1 image \
                            --format template \
                            --template "@/workspace/infra/trivy/html.tpl" \
                            -o /workspace/cve_report.html \
                            --severity CRITICAL,HIGH ${APP_REPO_NAME}:${BUILD_NUMBER}
                        """
                    }
                    
                    publishHTML(target: [
                        allowMissing: false,
                        alwaysLinkToLastBuild: true,
                        keepAll: true,
                        reportDir: ".",
                        reportFiles: 'cve_report.html',
                        reportName: 'Security Report'
                    ])
                }
            }
        }
        
        stage('Deploy') {
            agent { label "master" }
            steps {
                script {
                    // Agora usamos o Secret File criado no Jenkins
                    withCredentials([file(credentialsId: 'piquetProdEnv', variable: 'PROD_ENV')]) {
                        sshagent(credentials: ['deploy_piquet_prod']) {
                            sh """
                                ssh -o StrictHostKeyChecking=no deployer@149.36.249.12 "
                                cd project-laravel && \
                                cat > .env && \
                                sed -i 's|${APP_REPO_NAME}:.*|${APP_REPO_NAME}:${BUILD_NUMBER}|g' docker-compose.yaml && \
                                docker compose up -d && \
                                docker image prune -f
                                " < \$PROD_ENV
                            """
                        }
                    }
                }
            }
        }
    }
    
    post {
        always {
            script {
                node('master') { cleanWs() }
                node('Linux-2-8') { 
                    cleanWs()
                    sh "docker image prune -f --filter \"until=24h\""
                }
            }
        }
    }
}